"""Exercises the real detector: the model artifact, the inference runtime, and the budget.

These tests run where the model is really served — the ML image built with
INSTALL_YOLO=true and the fetched artifact at ML_MODEL_PATH — and skip where either is
absent, so the default dummy deployment and CI keep running without them.
"""

import os
import time
from io import BytesIO
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.config import DetectorKind, ModelSettings, Settings
from app.detector import ModelUnavailable, build_detector
from app.main import create_app
from app.yolo_backend import load_yolo_backend

LARAVEL_ML_TIMEOUT_SECONDS = 10
"""The `ML_SERVICE_TIMEOUT` Laravel reads from apps/api/config/services.php; a prediction
has to answer within it."""

MAX_UPLOAD_BYTES = 5 * 1024 * 1024
"""The largest image Laravel forwards to the service, from apps/api's
CreateMlPredictionRequest (5 MB, no larger than 4096x4096)."""


@pytest.fixture(scope="module")
def artifact() -> ModelSettings:
    """The artifact this deployment serves, skipping when it has none."""
    pytest.importorskip("ultralytics")

    settings = ModelSettings.from_environment(os.environ)
    reason = settings.unusable_reason()
    if reason is not None:
        pytest.skip(f"the real detector has no model to serve: {reason}")

    return settings


def largest_accepted_upload() -> bytes:
    """A JPEG at Laravel's largest accepted dimensions, inside its upload limit."""
    output = BytesIO()
    Image.new("RGB", (4096, 4096), (122, 116, 108)).save(output, "JPEG", quality=80)

    return output.getvalue()


def test_a_bounded_prediction_answers_within_laravels_ml_timeout(artifact: ModelSettings) -> None:
    app = create_app(
        Settings(detector=DetectorKind.YOLO, model=artifact),
        load_backend=load_yolo_backend,
    )
    image = largest_accepted_upload()
    assert len(image) < MAX_UPLOAD_BYTES

    started_at = time.perf_counter()
    response = TestClient(app).post(
        "/v1/predict",
        files={"image": ("viewport.jpg", image, "image/jpeg")},
        data={
            "panorama_node_id": "1",
            "camera_yaw": "45.5",
            "camera_pitch": "-10",
            "camera_fov": "90",
        },
    )
    elapsed_seconds = time.perf_counter() - started_at

    assert response.status_code == 200
    assert elapsed_seconds < LARAVEL_ML_TIMEOUT_SECONDS
    body = response.json()
    assert body["model_version"] == artifact.model_version
    assert isinstance(body["inference_ms"], int)
    assert len(body["detections"]) <= 100


def test_a_corrupt_artifact_degrades_the_service_instead_of_stopping_it(tmp_path: Path) -> None:
    pytest.importorskip("ultralytics")

    corrupt = tmp_path / "yolov8n.pt"
    corrupt.write_bytes(b"this is not a checkpoint")
    settings = Settings(
        detector=DetectorKind.YOLO,
        model=ModelSettings(
            artifact_path=corrupt,
            model_version="corrupt-v1",
            input_size=640,
            confidence_threshold=0.25,
        ),
    )

    detector = build_detector(settings, load_backend=load_yolo_backend)

    assert not detector.status.ready
    assert "could not be loaded" in str(detector.status.reason)
    with pytest.raises(ModelUnavailable):
        detector.predict(b"any image bytes")
