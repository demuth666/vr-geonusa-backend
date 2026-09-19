import sys
from collections.abc import Sequence
from io import BytesIO
from pathlib import Path

import pytest
from PIL import Image

from app.config import DetectorKind, ModelSettings, Settings
from app.detector import DummyDetector, YoloDetector, build_detector
from app.inference import RawDetection


class FakeBackend:
    """A substituted inference backend: no model artifact, no inference runtime."""

    def __init__(
        self,
        detections: Sequence[RawDetection] = (),
        class_names: dict[int, str] | None = None,
        input_size: int = 640,
    ) -> None:
        self.class_names = {0: "stupa"} if class_names is None else class_names
        self.input_size = input_size
        self._detections = list(detections)
        self.received: list[Image.Image] = []

    def infer(self, image: Image.Image) -> Sequence[RawDetection]:
        self.received.append(image)

        return self._detections


def png(width: int = 800, height: int = 400) -> bytes:
    output = BytesIO()
    Image.new("RGB", (width, height)).save(output, "PNG")

    return output.getvalue()


def detection(class_index: int = 0, confidence: float = 0.9) -> RawDetection:
    """A detection covering the submitted image, in the coordinates of a 640 model input."""
    return RawDetection(class_index=class_index, confidence=confidence, box=(0.0, 160.0, 640.0, 480.0))


def yolo_settings() -> Settings:
    return Settings(
        detector=DetectorKind.YOLO,
        model=ModelSettings(
            artifact_path=Path("artifacts/yolov8n.pt"),
            model_version="yolov8n-coco-v1",
            input_size=640,
            confidence_threshold=0.25,
        ),
    )


def test_dummy_detector_returns_the_versioned_contract() -> None:
    prediction = DummyDetector().predict(b"image bytes")

    assert prediction == {
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


def test_yolo_detector_returns_the_prediction_contract() -> None:
    detector = YoloDetector(backend=FakeBackend([detection()]), model_version="yolov8n-coco-v1")

    prediction = detector.predict(png())

    assert prediction["model_version"] == "yolov8n-coco-v1"
    assert isinstance(prediction["inference_ms"], int)
    assert prediction["inference_ms"] >= 0
    assert prediction["detections"] == [
        {
            "class": "stupa",
            "confidence": 0.9,
            "bounding_box": [0, 0, 800, 400],
        }
    ]


def test_yolo_detector_reports_the_models_own_label_names() -> None:
    backend = FakeBackend([detection(class_index=1)], class_names={0: "stupa", 1: "relief panel"})

    prediction = YoloDetector(backend=backend, model_version="v1").predict(png())

    assert prediction["detections"][0]["class"] == "relief panel"


def test_yolo_detector_keeps_a_detection_the_model_leaves_unlabelled() -> None:
    backend = FakeBackend([detection(class_index=42)], class_names={})

    prediction = YoloDetector(backend=backend, model_version="v1").predict(png())

    assert prediction["detections"][0]["class"] == "42"


def test_yolo_detector_preprocesses_for_the_model_before_inferring() -> None:
    backend = FakeBackend([detection()], input_size=512)

    YoloDetector(backend=backend, model_version="v1").predict(png(width=300, height=300))

    assert [image.size for image in backend.received] == [(512, 512)]


def test_yolo_detector_maps_boxes_back_to_the_submitted_image() -> None:
    # The model input is padded top and bottom, so the model's box has to lose that
    # padding before it means anything on the submitted image.
    backend = FakeBackend([RawDetection(class_index=0, confidence=0.7, box=(0.0, 320.0, 640.0, 640.0))])

    prediction = YoloDetector(backend=backend, model_version="v1").predict(png(width=1600, height=800))

    # A 1600x800 image is scaled by 0.4 and padded 160px top and bottom.
    assert prediction["detections"][0]["bounding_box"] == [0, 400, 1600, 800]


def test_mapped_boxes_stay_inside_the_submitted_image_and_the_range_laravel_accepts() -> None:
    # Laravel accepts coordinates between 0 and 4096, which is also the largest image it
    # lets through, so clamping to the submitted image keeps predictions acceptable.
    backend = FakeBackend([RawDetection(class_index=0, confidence=0.7, box=(-90.0, 0.0, 980.0, 800.0))])

    prediction = YoloDetector(backend=backend, model_version="v1").predict(png(width=1200, height=900))

    box = prediction["detections"][0]["bounding_box"]
    assert all(isinstance(coordinate, int) for coordinate in box)
    assert all(0 <= coordinate <= 4096 for coordinate in box)
    assert 0 <= box[0] <= box[2] <= 1200
    assert 0 <= box[1] <= box[3] <= 900


def test_yolo_detector_returns_an_empty_detection_list_when_the_model_finds_nothing() -> None:
    prediction = YoloDetector(backend=FakeBackend(), model_version="v1").predict(png())

    assert prediction["detections"] == []


def test_yolo_detector_keeps_the_most_confident_detections_up_to_the_limit() -> None:
    backend = FakeBackend(
        [
            detection(confidence=0.4),
            detection(confidence=0.9),
            detection(confidence=0.6),
        ]
    )

    prediction = YoloDetector(backend=backend, model_version="v1", max_detections=2).predict(png())

    assert [item["confidence"] for item in prediction["detections"]] == [0.9, 0.6]


def test_yolo_detector_keeps_detections_within_what_laravel_accepts_by_default() -> None:
    backend = FakeBackend([detection(confidence=1.0 - index / 1000) for index in range(150)])

    prediction = YoloDetector(backend=backend, model_version="v1").predict(png())

    assert len(prediction["detections"]) == 100


def test_build_detector_serves_the_dummy_detector_without_loading_anything() -> None:
    def load(_: ModelSettings) -> FakeBackend:
        raise AssertionError("the dummy detector must not load a model artifact")

    assert isinstance(build_detector(Settings.from_environment({}), load), DummyDetector)


def test_build_detector_loads_the_model_artifact_once_and_reuses_it() -> None:
    loads: list[ModelSettings] = []

    def load(model: ModelSettings) -> FakeBackend:
        loads.append(model)

        return FakeBackend()

    detector = build_detector(yolo_settings(), load)

    predictions = [detector.predict(png()) for _ in range(3)]

    assert len(loads) == 1
    assert {prediction["model_version"] for prediction in predictions} == {"yolov8n-coco-v1"}


def test_build_detector_keeps_the_configured_model_version_across_predictions() -> None:
    detector = build_detector(yolo_settings(), lambda model: FakeBackend([detection()]))

    assert detector.predict(png())["model_version"] == "yolov8n-coco-v1"
    assert detector.predict(png())["model_version"] == "yolov8n-coco-v1"


def test_serving_the_dummy_detector_never_needs_the_inference_runtime() -> None:
    # The dummy detector, this suite, and CI all run without Ultralytics and PyTorch
    # installed, so the service has to import them only when a YOLO detector is configured.
    assert "ultralytics" not in sys.modules


def test_build_detector_refuses_a_detector_it_has_no_model_settings_for() -> None:
    def load(_: ModelSettings) -> FakeBackend:
        raise AssertionError("a detector without model settings must not load anything")

    with pytest.raises(ValueError, match="yolo"):
        build_detector(Settings(detector=DetectorKind.YOLO, model=None), load)
