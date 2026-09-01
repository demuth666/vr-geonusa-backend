from io import BytesIO

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.main import app


def png() -> bytes:
    output = BytesIO()
    Image.new("RGB", (1, 1)).save(output, "PNG")

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
