import pytest
from fastapi.testclient import TestClient

from app.config import ModelSettings, Settings
from app.detector import DUMMY_MODEL_VERSION
from app.inference import InferenceBackend
from app.main import create_app
from conftest import ARTIFACT_PATH, FakeBackend, yolo_environment


def health(**overrides: str) -> dict[str, str | None]:
    """The health body of a service started with the given deployment environment."""
    settings = Settings.from_environment(yolo_environment(**overrides))

    app = create_app(settings, load_backend=lambda model: FakeBackend())

    response = TestClient(app).get("/health")

    # Health answers while the process is up, degraded or not; only the body says which.
    assert response.status_code == 200

    return response.json()["data"]


def test_health_reports_the_dummy_detector_it_serves() -> None:
    response = TestClient(create_app(Settings.from_environment({}))).get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "data": {
            "status": "ok",
            "detector": "dummy",
            "model_version": DUMMY_MODEL_VERSION,
            "reason": None,
        }
    }


def test_startup_with_a_present_artifact_reports_the_loaded_model_version() -> None:
    app = create_app(Settings.from_environment(yolo_environment()), load_backend=lambda model: FakeBackend())

    response = TestClient(app).get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "data": {
            "status": "ok",
            "detector": "yolo",
            "model_version": "yolov8n-coco-v1",
            "reason": None,
        }
    }


def test_startup_with_a_missing_artifact_reports_the_degraded_state_while_health_serves() -> None:
    body = health(ML_MODEL_PATH="artifacts/missing.pt")

    assert body["status"] == "degraded"
    assert body["detector"] == "yolo"
    assert body["model_version"] is None
    assert "missing.pt" in str(body["reason"])


def test_startup_with_a_corrupt_artifact_reports_the_degraded_state_while_health_serves() -> None:
    def load(_: ModelSettings) -> InferenceBackend:
        raise RuntimeError("PytorchStreamReader failed locating file data.pkl")

    app = create_app(Settings.from_environment(yolo_environment()), load_backend=load)

    response = TestClient(app).get("/health")

    assert response.status_code == 200
    assert response.json()["data"] == {
        "status": "degraded",
        "detector": "yolo",
        "model_version": None,
        "reason": f"the model artifact at {ARTIFACT_PATH} could not be loaded",
    }


@pytest.mark.parametrize(
    ("overrides", "missing_setting"),
    [
        ({"ML_MODEL_PATH": ""}, "ML_MODEL_PATH"),
        ({"ML_MODEL_VERSION": " "}, "ML_MODEL_VERSION"),
    ],
)
def test_startup_with_a_model_setting_left_out_reports_what_is_missing(
    overrides: dict[str, str], missing_setting: str
) -> None:
    body = health(**overrides)

    assert body["status"] == "degraded"
    assert body["detector"] == "yolo"
    assert body["model_version"] is None
    assert missing_setting in str(body["reason"])
