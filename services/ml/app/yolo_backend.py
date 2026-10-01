"""Adapter over the Ultralytics YOLO package.

Ultralytics and its PyTorch runtime are imported when a YOLO detector is actually
configured, so deployments and tests that select the dummy detector never need them
installed.
"""

from collections.abc import Iterable, Mapping, Sequence
from typing import Any, Protocol

from PIL import Image

from app.config import ModelSettings
from app.inference import RawDetection
from app.preprocessing import PADDING


class YoloModel(Protocol):
    """The slice of the Ultralytics model API this adapter uses."""

    @property
    def names(self) -> Mapping[int, str]: ...

    def predict(self, source: Any, **kwargs: Any) -> Iterable[Any]: ...


class YoloBackend:
    """Runs an already-loaded YOLO model over images letterboxed to its input size."""

    def __init__(self, model: YoloModel, input_size: int, confidence_threshold: float) -> None:
        self._model = model
        self._input_size = input_size
        self._confidence_threshold = confidence_threshold

    @property
    def input_size(self) -> int:
        return self._input_size

    @property
    def class_names(self) -> Mapping[int, str]:
        return dict(self._model.names)

    def infer(self, image: Image.Image) -> Sequence[RawDetection]:
        results = list(
            self._model.predict(
                image,
                imgsz=self._input_size,
                conf=self._confidence_threshold,
                verbose=False,
            )
        )

        if not results or results[0].boxes is None:
            return []

        return [
            RawDetection(class_index=int(box.cls), confidence=float(box.conf), box=_xyxy(box))
            for box in results[0].boxes
        ]


def load_yolo_backend(settings: ModelSettings) -> YoloBackend:
    """Loads the configured artifact. Called once, when the service starts."""
    # Ultralytics downloads a checkpoint that is not on disk; an artifact the deployment
    # never provided must fail here instead of reaching for the network at startup. The
    # check runs before the import so a missing artifact is reported without the runtime.
    if settings.artifact_path is None or not settings.artifact_path.is_file():
        raise FileNotFoundError(f"the model artifact is not there: {settings.artifact_path}")

    from ultralytics import YOLO

    backend = YoloBackend(
        model=YOLO(str(settings.artifact_path)),
        input_size=settings.input_size,
        confidence_threshold=settings.confidence_threshold,
    )

    # The model's first inference pays for lazily initialised kernels and thread pools,
    # which is seconds a prediction request would otherwise spend against the timeout the
    # caller configured. Doing it here moves that cost into startup.
    backend.infer(Image.new("RGB", (settings.input_size, settings.input_size), PADDING))

    return backend


def _xyxy(box: Any) -> tuple[float, float, float, float]:
    left, top, right, bottom = (float(value) for value in box.xyxy[0])

    return left, top, right, bottom
