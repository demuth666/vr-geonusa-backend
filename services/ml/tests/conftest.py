from collections.abc import Mapping, Sequence
from io import BytesIO
from pathlib import Path

from PIL import Image

from app.inference import RawDetection

ARTIFACT_PATH = Path(__file__)
"""A file that is there, standing in for the model artifact in the deployments under test."""


def png(width: int = 800, height: int = 400) -> bytes:
    """A viewport-sized PNG, unless a test is about some other shape of image."""
    output = BytesIO()
    Image.new("RGB", (width, height)).save(output, "PNG")

    return output.getvalue()


def yolo_environment(**overrides: str) -> dict[str, str]:
    """The environment of a YOLO deployment that has an artifact to load."""
    return {
        "ML_DETECTOR": "yolo",
        "ML_MODEL_PATH": str(ARTIFACT_PATH),
        "ML_MODEL_VERSION": "yolov8n-coco-v1",
        **overrides,
    }


class FakeBackend:
    """A substituted inference backend: no model artifact, no inference runtime.

    The default test suite and CI never install the inference runtime, so everything that
    would use it is replaced by this stand-in.
    """

    def __init__(
        self,
        detections: Sequence[RawDetection] = (),
        class_names: Mapping[int, str] | None = None,
        input_size: int = 640,
    ) -> None:
        self.class_names = {0: "stupa"} if class_names is None else dict(class_names)
        self.input_size = input_size
        self._detections = list(detections)
        self.received: list[Image.Image] = []

    def infer(self, image: Image.Image) -> Sequence[RawDetection]:
        self.received.append(image)

        return self._detections


def detection(class_index: int = 0, confidence: float = 0.9) -> RawDetection:
    """A detection covering the submitted image, in the coordinates of a 640 model input."""
    return RawDetection(class_index=class_index, confidence=confidence, box=(0.0, 160.0, 640.0, 480.0))
