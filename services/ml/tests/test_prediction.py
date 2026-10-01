from collections.abc import Sequence
from io import BytesIO

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.detector import YoloDetector
from app.inference import RawDetection
from app.main import app


def png(width: int = 1, height: int = 1) -> bytes:
    output = BytesIO()
    Image.new("RGB", (width, height)).save(output, "PNG")

    return output.getvalue()


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


class FixedBackend:
    """A substituted inference backend that needs no model artifact."""

    input_size = 640
    class_names = {7: "stupa"}

    def infer(self, image: Image.Image) -> Sequence[RawDetection]:
        """A detection covering the submitted image, in the coordinates of a 640 model input."""
        return [RawDetection(class_index=7, confidence=0.8, box=(0.0, 160.0, 640.0, 480.0))]


class EmptyBackend(FixedBackend):
    """A substituted inference backend whose model finds nothing in the image."""

    def infer(self, image: Image.Image) -> Sequence[RawDetection]:
        return []


def test_predict_returns_the_dummy_prediction_contract() -> None:
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


def test_predict_serves_a_loaded_model_through_the_unchanged_route(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        "app.main.detector",
        YoloDetector(backend=FixedBackend(), model_version="yolov8n-coco-v1"),
    )

    response = TestClient(app).post(
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


def test_predict_succeeds_with_no_detections_when_the_model_finds_nothing(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        "app.main.detector",
        YoloDetector(backend=EmptyBackend(), model_version="yolov8n-coco-v1"),
    )

    response = TestClient(app).post(
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
    response = TestClient(app).post(
        "/v1/predict",
        **prediction_request(image),
    )

    assert response.status_code == 415
    assert response.json() == {"detail": "image must be a valid image file"}


def test_prediction_schema_documents_the_multipart_contract() -> None:
    schema = TestClient(app).get("/openapi.json").json()
    operation = schema["paths"]["/v1/predict"]["post"]

    assert "multipart/form-data" in operation["requestBody"]["content"]
    assert operation["responses"]["200"]["content"]["application/json"]["schema"] == {
        "$ref": "#/components/schemas/PredictionResponse"
    }
