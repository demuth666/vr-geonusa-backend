import sys
from pathlib import Path

import pytest

from app.config import DetectorKind, ModelSettings, Settings
from app.detector import (
    DUMMY_MODEL_VERSION,
    DetectorStatus,
    DummyDetector,
    ModelUnavailable,
    UnavailableDetector,
    YoloDetector,
    build_detector,
)
from app.inference import RawDetection
from conftest import ARTIFACT_PATH, FakeBackend, detection, png


def yolo_settings(artifact_path: Path | None = ARTIFACT_PATH) -> Settings:
    """A YOLO deployment whose artifact is a file that is there; the load is still faked."""
    return Settings(
        detector=DetectorKind.YOLO,
        model=ModelSettings(
            artifact_path=artifact_path,
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


def test_build_detector_reports_the_dummy_detector_and_the_version_it_predicts_with() -> None:
    detector = build_detector(Settings.from_environment({}), lambda model: FakeBackend())

    assert detector.status == DetectorStatus(detector=DetectorKind.DUMMY, model_version=DUMMY_MODEL_VERSION)
    assert detector.status.ready


def test_build_detector_reports_the_model_version_of_the_detector_it_loaded() -> None:
    detector = build_detector(yolo_settings(), lambda model: FakeBackend())

    assert detector.status == DetectorStatus(detector=DetectorKind.YOLO, model_version="yolov8n-coco-v1")
    assert detector.status.ready


@pytest.mark.parametrize("artifact_path", [None, Path("artifacts/missing.pt")])
def test_build_detector_degrades_when_the_artifact_is_not_usable(artifact_path: Path | None) -> None:
    def load(_: ModelSettings) -> FakeBackend:
        raise AssertionError("an artifact that is not there must not be loaded")

    detector = build_detector(yolo_settings(artifact_path), load)

    assert isinstance(detector, UnavailableDetector)
    assert detector.status.detector is DetectorKind.YOLO
    assert detector.status.model_version is None
    assert not detector.status.ready
    assert "ML_MODEL_PATH" in str(detector.status.reason)

    with pytest.raises(ModelUnavailable, match="ML_MODEL_PATH"):
        detector.predict(png())


def test_build_detector_names_a_missing_runtime_rather_than_blaming_the_artifact() -> None:
    def load(_: ModelSettings) -> FakeBackend:
        raise ModuleNotFoundError("No module named 'ultralytics'")

    detector = build_detector(yolo_settings(), load)

    assert isinstance(detector, UnavailableDetector)
    assert "INSTALL_YOLO" in str(detector.status.reason)

    with pytest.raises(ModelUnavailable):
        detector.predict(png())


def test_build_detector_degrades_when_the_artifact_cannot_be_loaded() -> None:
    def load(_: ModelSettings) -> FakeBackend:
        raise RuntimeError("PytorchStreamReader failed locating file data.pkl")

    detector = build_detector(yolo_settings(), load)

    assert isinstance(detector, UnavailableDetector)
    # The status names the artifact without echoing the model library's traceback.
    assert ARTIFACT_PATH.name in str(detector.status.reason)
    assert "could not be loaded" in str(detector.status.reason)
    assert "PytorchStreamReader" not in str(detector.status.reason)

    with pytest.raises(ModelUnavailable):
        detector.predict(png())
