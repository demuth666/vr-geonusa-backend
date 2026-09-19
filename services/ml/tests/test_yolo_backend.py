from types import SimpleNamespace
from typing import Any

from PIL import Image

from app.inference import RawDetection
from app.yolo_backend import YoloBackend


class FakeYoloModel:
    """A stand-in for an Ultralytics model, shaped like the results it returns."""

    def __init__(self, boxes: tuple[Any, ...] = (), names: dict[int, str] | None = None) -> None:
        self.names = {0: "stupa", 1: "relief panel"} if names is None else names
        self.calls: list[dict[str, Any]] = []
        self._boxes = list(boxes)
        self._results = True

    def without_results(self) -> "FakeYoloModel":
        self._results = False

        return self

    def predict(self, source: Any, **kwargs: Any) -> list[Any]:
        self.calls.append({"source": source, **kwargs})

        return [SimpleNamespace(boxes=self._boxes)] if self._results else []


def box(class_index: int, confidence: float, coordinates: tuple[float, float, float, float]) -> Any:
    return SimpleNamespace(cls=class_index, conf=confidence, xyxy=[list(coordinates)])


def backend_for(model: FakeYoloModel, input_size: int = 640, confidence_threshold: float = 0.25) -> YoloBackend:
    return YoloBackend(model=model, input_size=input_size, confidence_threshold=confidence_threshold)


def test_yolo_backend_reports_the_models_own_label_names() -> None:
    assert dict(backend_for(FakeYoloModel()).class_names) == {0: "stupa", 1: "relief panel"}


def test_yolo_backend_converts_model_boxes_into_detections_on_the_model_input() -> None:
    model = FakeYoloModel([box(1, 0.8123, (12.5, 40.0, 300.25, 480.0))])

    detections = backend_for(model).infer(Image.new("RGB", (640, 640)))

    assert detections == [
        RawDetection(class_index=1, confidence=0.8123, box=(12.5, 40.0, 300.25, 480.0)),
    ]


def test_yolo_backend_runs_the_model_at_the_configured_input_size_and_threshold() -> None:
    model = FakeYoloModel()
    image = Image.new("RGB", (512, 512))

    backend_for(model, input_size=512, confidence_threshold=0.4).infer(image)

    assert model.calls == [
        {"source": image, "imgsz": 512, "conf": 0.4, "verbose": False},
    ]


def test_yolo_backend_reports_no_detections_when_the_model_finds_nothing() -> None:
    assert backend_for(FakeYoloModel()).infer(Image.new("RGB", (640, 640))) == []


def test_yolo_backend_reports_no_detections_when_the_model_returns_no_results() -> None:
    model = FakeYoloModel().without_results()

    assert backend_for(model).infer(Image.new("RGB", (640, 640))) == []
