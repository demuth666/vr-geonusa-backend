import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.config import Settings
from app.inference import InferenceBackend
from app.main import create_app
from conftest import FakeBackend, detection, png, yolo_environment


def prediction_request(image: tuple[str, bytes, str]) -> dict[str, object]:
    return {
        "files": {"image": image},
        "data": {
            "panorama_node_id": "1",
            "camera_yaw": "45.5",
            "camera_pitch": "-10",
            "camera_fov": "90",
        },
    }


def finds_a_stupa() -> FakeBackend:
    """An inference backend whose model finds one class, covering the submitted image."""
    return FakeBackend([detection(class_index=7, confidence=0.8)], class_names={7: "stupa"})


def app_with(backend: InferenceBackend, **overrides: str) -> FastAPI:
    return create_app(Settings.from_environment(yolo_environment(**overrides)), load_backend=lambda model: backend)


def test_predict_returns_the_dummy_prediction_contract() -> None:
    app = create_app(Settings.from_environment({}))

    response = TestClient(app).post(
        "/v1/predict",
        **prediction_request(("viewport.png", png(), "image/png")),
    )

    assert response.status_code == 200
    assert response.json() == {
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


def test_predict_serves_a_loaded_model_through_the_unchanged_route() -> None:
    response = TestClient(app_with(finds_a_stupa())).post(
        "/v1/predict",
        **prediction_request(("viewport.png", png(800, 400), "image/png")),
    )

    assert response.status_code == 200
    body = response.json()
    assert body["model_version"] == "yolov8n-coco-v1"
    assert isinstance(body["inference_ms"], int)
    assert body["inference_ms"] >= 0
    assert body["detections"] == [
        {
            "class": "stupa",
            "confidence": 0.8,
            "bounding_box": [0, 0, 800, 400],
        }
    ]


def test_predict_succeeds_with_no_detections_when_the_model_finds_nothing() -> None:
    response = TestClient(app_with(FakeBackend())).post(
        "/v1/predict",
        **prediction_request(("viewport.png", png(800, 400), "image/png")),
    )

    assert response.status_code == 200
    assert response.json()["detections"] == []


@pytest.mark.parametrize(
    "image",
    [
        ("notes.txt", b"not an image", "text/plain"),
        ("corrupt.png", b"\x89PNG\r\n\x1a\n", "image/png"),
    ],
)
def test_predict_rejects_non_images(image: tuple[str, bytes, str]) -> None:
    app = create_app(Settings.from_environment({}))

    response = TestClient(app).post(
        "/v1/predict",
        **prediction_request(image),
    )

    assert response.status_code == 415
    assert response.json() == {"detail": "image must be a valid image file"}


def test_predictions_answer_a_controlled_error_while_the_model_is_unavailable() -> None:
    app = app_with(finds_a_stupa(), ML_MODEL_PATH="artifacts/missing.pt")

    response = TestClient(app).post(
        "/v1/predict",
        **prediction_request(("viewport.png", png(), "image/png")),
    )

    assert response.status_code == 503
    assert "ML_MODEL_PATH" in response.json()["detail"]
    # The caller gets a controlled error naming the cause, never a stack trace.
    assert "Traceback" not in response.text


def test_prediction_schema_documents_the_multipart_contract() -> None:
    schema = TestClient(create_app(Settings.from_environment({}))).get("/openapi.json").json()
    operation = schema["paths"]["/v1/predict"]["post"]

    assert "multipart/form-data" in operation["requestBody"]["content"]
    assert operation["responses"]["200"]["content"]["application/json"]["schema"] == {
        "$ref": "#/components/schemas/PredictionResponse"
    }
