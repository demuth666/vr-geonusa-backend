import time
from collections.abc import Callable, Sequence
from io import BytesIO
from typing import Protocol

from PIL import Image

from app.config import DetectorKind, ModelSettings, Settings
from app.inference import InferenceBackend, RawDetection
from app.preprocessing import letterbox

MAX_DETECTIONS = 100
"""Laravel rejects a prediction that carries more detections than this."""


class Detector(Protocol):
    def predict(self, image: bytes) -> dict[str, object]: ...


class DummyDetector:
    """The contract placeholder used by tests, CI, and deployments without a model artifact."""

    def predict(self, image: bytes) -> dict[str, object]:
        return {
            "model_version": "dummy-v1",
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
        self._model_version = model_version
        self._max_detections = max_detections

    def predict(self, image: bytes) -> dict[str, object]:
        with Image.open(BytesIO(image)) as submitted:
            model_input, placement = letterbox(submitted, self._backend.input_size)

        started_at = time.perf_counter()
        detections = self._backend.infer(model_input)
        inference_ms = round((time.perf_counter() - started_at) * 1000)

        return {
            "model_version": self._model_version,
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


def build_detector(
    settings: Settings,
    load_backend: Callable[[ModelSettings], InferenceBackend],
) -> Detector:
    """Builds the configured detector, loading its model artifact once, at startup."""
    if settings.detector is DetectorKind.DUMMY:
        return DummyDetector()

    model = settings.model
    if model is None:
        raise ValueError(f"the {settings.detector.value} detector requires model settings")

    return YoloDetector(backend=load_backend(model), model_version=model.model_version)
