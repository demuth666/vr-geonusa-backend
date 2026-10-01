from pathlib import Path

import pytest

from app.config import DetectorKind, Settings


def environment(**overrides: str) -> dict[str, str]:
    """A YOLO deployment, with the test file itself standing in for the model artifact."""
    return {
        "ML_DETECTOR": "yolo",
        "ML_MODEL_PATH": __file__,
        "ML_MODEL_VERSION": "yolov8n-coco-v1",
        **overrides,
    }


def test_settings_fall_back_to_the_dummy_detector() -> None:
    settings = Settings.from_environment({})

    assert settings.detector is DetectorKind.DUMMY
    assert settings.model is None


def test_settings_read_the_configured_model_artifact() -> None:
    settings = Settings.from_environment(environment())

    assert settings.detector is DetectorKind.YOLO
    assert settings.model is not None
    assert settings.model.artifact_path == Path(__file__)
    assert settings.model.model_version == "yolov8n-coco-v1"
    assert settings.model.input_size == 640
    assert settings.model.confidence_threshold == 0.25


def test_settings_accept_tuned_detection_values() -> None:
    settings = Settings.from_environment(
        environment(ML_IMAGE_SIZE="512", ML_CONFIDENCE_THRESHOLD="0.4")
    )

    assert settings.model is not None
    assert settings.model.input_size == 512
    assert settings.model.confidence_threshold == 0.4


def test_blank_settings_fall_back_to_their_defaults() -> None:
    # Compose passes a variable it was not given as an empty string, which is not a value.
    settings = Settings.from_environment(environment(ML_IMAGE_SIZE="", ML_CONFIDENCE_THRESHOLD=" "))

    assert settings.model is not None
    assert settings.model.input_size == 640
    assert settings.model.confidence_threshold == 0.25


def test_a_blank_detector_falls_back_to_the_dummy_detector() -> None:
    assert Settings.from_environment({"ML_DETECTOR": ""}).detector is DetectorKind.DUMMY


def test_an_unknown_detector_names_the_supported_ones() -> None:
    with pytest.raises(ValueError) as error:
        Settings.from_environment({"ML_DETECTOR": "yolov9"})

    assert "dummy" in str(error.value)
    assert "yolo" in str(error.value)


@pytest.mark.parametrize("configured", ["", "artifacts/missing.pt"])
def test_a_model_artifact_that_is_not_there_stops_the_service_from_starting(configured: str) -> None:
    with pytest.raises(ValueError, match="ML_MODEL_PATH"):
        Settings.from_environment(environment(ML_MODEL_PATH=configured))


@pytest.mark.parametrize("configured", ["", "   "])
def test_a_detector_loading_an_artifact_requires_an_explicit_model_version(configured: str) -> None:
    with pytest.raises(ValueError, match="ML_MODEL_VERSION"):
        Settings.from_environment(environment(ML_MODEL_VERSION=configured))


@pytest.mark.parametrize(
    ("name", "configured"),
    [
        ("ML_IMAGE_SIZE", "0"),
        ("ML_IMAGE_SIZE", "-640"),
        ("ML_IMAGE_SIZE", "large"),
        ("ML_CONFIDENCE_THRESHOLD", "1.5"),
        ("ML_CONFIDENCE_THRESHOLD", "-0.1"),
        ("ML_CONFIDENCE_THRESHOLD", "certain"),
    ],
)
def test_invalid_detection_settings_are_rejected(name: str, configured: str) -> None:
    with pytest.raises(ValueError, match=name):
        Settings.from_environment(environment(**{name: configured}))


def test_a_dummy_deployment_needs_no_model_settings_at_all() -> None:
    settings = Settings.from_environment(
        {"ML_DETECTOR": "dummy", "ML_IMAGE_SIZE": "not-a-size", "ML_CONFIDENCE_THRESHOLD": "nonsense"}
    )

    assert settings.detector is DetectorKind.DUMMY
