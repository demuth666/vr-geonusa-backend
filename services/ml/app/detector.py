import logging
import time
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from io import BytesIO
from typing import Protocol

from PIL import Image

from app.config import DetectorKind, ModelSettings, Settings
from app.inference import InferenceBackend, RawDetection
from app.preprocessing import letterbox

MAX_DETECTIONS = 100
"""Laravel rejects a prediction that carries more detections than this."""

DUMMY_MODEL_VERSION = "dummy-v1"
"""The version the dummy detector stamps on its predictions."""

logger = logging.getLogger(__name__)


class ModelUnavailable(RuntimeError):
    """A prediction was asked of a detector whose model artifact never loaded."""


@dataclass(frozen=True)
class DetectorStatus:
    """What the running service can say about the detector it is serving.

    `reason` names why the detector cannot predict, or is None when it can. The status is
    reported on `/health`, which is what makes a deployment discoverable from the running
    service: its detector, its model version, and its readiness.
    """

    detector: DetectorKind
    model_version: str | None
    reason: str | None = None

    @property
    def ready(self) -> bool:
        return self.reason is None


class Detector(Protocol):
    @property
    def status(self) -> DetectorStatus: ...

    def predict(self, image: bytes) -> dict[str, object]: ...


class DummyDetector:
    """The contract placeholder used by tests, CI, and deployments without a model artifact."""

    def __init__(self) -> None:
        self.status = DetectorStatus(detector=DetectorKind.DUMMY, model_version=DUMMY_MODEL_VERSION)

    def predict(self, image: bytes) -> dict[str, object]:
        return {
            "model_version": DUMMY_MODEL_VERSION,
            "inference_ms": 10,
            "detections": [
                {
                    "class": "stupa",
                    "confidence": 0.95,
                    "bounding_box": [10, 20, 100, 120],
                }
            ],
        }


class YoloDetector:
    """Serves a loaded model artifact through the prediction contract.

    The backend is already loaded when this detector is built, so predicting never pays
    for a cold load. Preprocessing and mapping detections back onto the submitted image
    happen here, keeping the backend a plain "pixels in, detections out" model.
    """

    def __init__(
        self,
        backend: InferenceBackend,
        model_version: str,
        max_detections: int = MAX_DETECTIONS,
    ) -> None:
        self._backend = backend
        self._max_detections = max_detections
        self.status = DetectorStatus(detector=DetectorKind.YOLO, model_version=model_version)

    def predict(self, image: bytes) -> dict[str, object]:
        with Image.open(BytesIO(image)) as submitted:
            model_input, placement = letterbox(submitted, self._backend.input_size)

        started_at = time.perf_counter()
        detections = self._backend.infer(model_input)
        inference_ms = round((time.perf_counter() - started_at) * 1000)

        return {
            # The version predictions are stamped with is the version the service reports.
            "model_version": self.status.model_version,
            "inference_ms": inference_ms,
            "detections": [
                {
                    "class": self._class_key(detection.class_index),
                    "confidence": detection.confidence,
                    "bounding_box": placement.to_original_box(detection.box),
                }
                for detection in self._strongest(detections)
            ],
        }

    def _class_key(self, class_index: int) -> str:
        """Reports the model's own label name; an unlabelled class still reports a detection."""
        return self._backend.class_names.get(class_index, str(class_index))

    def _strongest(self, detections: Sequence[RawDetection]) -> list[RawDetection]:
        ranked = sorted(detections, key=lambda detection: detection.confidence, reverse=True)

        return ranked[: self._max_detections]


class UnavailableDetector:
    """Stands in for a detector whose model artifact never loaded.

    The service starts anyway: `/health` reports the reason real inference is unavailable,
    and every prediction answers a controlled error instead of a stack trace or a request
    left hanging.
    """

    def __init__(self, detector: DetectorKind, reason: str) -> None:
        self._reason = reason
        self.status = DetectorStatus(detector=detector, model_version=None, reason=reason)

    def predict(self, image: bytes) -> dict[str, object]:
        raise ModelUnavailable(self._reason)


def build_detector(
    settings: Settings,
    load_backend: Callable[[ModelSettings], InferenceBackend],
) -> Detector:
    """Builds the configured detector, loading its model artifact once, at startup.

    A model that is missing, unreadable, or unusable does not stop the service: it starts
    with a detector that reports why it cannot predict and refuses predictions with a
    controlled error, so a broken deployment stays observable instead of crash-looping.
    """
    if settings.detector is DetectorKind.DUMMY:
        return DummyDetector()

    model = settings.model
    if model is None:
        raise ValueError(f"the {settings.detector.value} detector requires model settings")

    reason = model.unusable_reason()
    if reason is not None:
        logger.warning("serving the %s detector without a model: %s", settings.detector.value, reason)

        return UnavailableDetector(detector=settings.detector, reason=reason)

    try:
        backend = load_backend(model)
    except ImportError:
        # The detector is selected but the image never installed its runtime, so the
        # artifact is not what is wrong and blaming it would send the operator the wrong way.
        logger.exception("the inference runtime is not installed in this image")

        return UnavailableDetector(
            detector=settings.detector,
            reason="the inference runtime is not installed in this image; build it with INSTALL_YOLO=true",
        )
    except Exception:
        # A corrupt or unusable artifact surfaces as whatever the model library raises. Log
        # the traceback for operators; the status deliberately keeps only a short reason.
        logger.exception("the model artifact at %s could not be loaded", model.artifact_path)

        return UnavailableDetector(
            detector=settings.detector,
            reason=f"the model artifact at {model.artifact_path} could not be loaded",
        )

    # unusable_reason() has already refused a model without a version, so the version the
    # detector stamps on predictions is a real one.
    assert model.model_version is not None

    detector = YoloDetector(backend=backend, model_version=model.model_version)
    logger.info("serving the %s detector with model version %s", settings.detector.value, model.model_version)

    return detector
