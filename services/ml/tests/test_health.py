from collections.abc import Sequence
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.config import ModelSettings, Settings
from app.detector import DUMMY_MODEL_VERSION
from app.inference import InferenceBackend, RawDetection
from app.main import create_app


def environment(**overrides: str) -> dict[str, str]:
    """A YOLO deployment, with the test file itself standing in for the model artifact."""
    return {
        "ML_DETECTOR": "yolo",
        "ML_MODEL_PATH": __file__,
        "ML_MODEL_VERSION": "yolov8n-coco-v1",
        **overrides,
    }


class FakeBackend:
    """A substituted inference backend that needs no model artifact."""

    @property
    def input_size(self) -> int:
        return 640

    @property
    def class_names(self) -> dict[int, str]:
        return {}

    def infer(self, image: Image.Image) -> Sequence[RawDetection]:
        return []


def health(**overrides: str) -> dict[str, str | None]:
    """The health body of a service started with the given deployment environment."""
    app = create_app(Settings.from_environment(environment(**overrides)), load_backend=lambda model: FakeBackend())

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
    app = create_app(Settings.from_environment(environment()), load_backend=lambda model: FakeBackend())

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

    app = create_app(Settings.from_environment(environment()), load_backend=load)

    response = TestClient(app).get("/health")

    assert response.status_code == 200
    assert response.json()["data"] == {
        "status": "degraded",
        "detector": "yolo",
        "model_version": None,
        "reason": f"the model artifact at {Path(__file__)} could not be loaded",
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
