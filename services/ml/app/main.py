import logging
from collections.abc import Callable
from typing import Annotated, Literal

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel, Field
from starlette.concurrency import run_in_threadpool

from app.config import DetectorKind, ModelSettings, Settings
from app.detector import ModelUnavailable, build_detector
from app.inference import InferenceBackend
from app.preprocessing import is_valid_image
from app.yolo_backend import load_yolo_backend


class Detection(BaseModel):
    class_: str = Field(alias="class")
    confidence: float
    bounding_box: list[int]


class PredictionResponse(BaseModel):
    model_version: str
    inference_ms: int
    detections: list[Detection]


class HealthStatus(BaseModel):
    """What the running service reports about the detector it is serving.

    `degraded` means real inference is unavailable and `reason` names the cause. Health
    keeps answering while the process is up, so a deployment whose artifact is missing
    stays observable instead of disappearing behind a crash loop.
    """

    status: Literal["ok", "degraded"]
    detector: DetectorKind
    model_version: str | None
    reason: str | None = None


def create_app(
    settings: Settings | None = None,
    load_backend: Callable[[ModelSettings], InferenceBackend] = load_yolo_backend,
) -> FastAPI:
    """Builds the service around the configured detector.

    This is the ASGI entry point (uvicorn `--factory`), so the artifact is loaded once, in
    the process that serves — before the app answers its first request, and before it
    reports itself ready.
    """
    app = FastAPI(title="VR-GeoNusa ML Service")

    # Uvicorn configures only its own loggers, so without a root handler the service's own
    # records — which detector and model it serves, and why it cannot — never reach the
    # container logs.
    logging.basicConfig(level=logging.INFO)

    detector = build_detector(settings or Settings.from_environment(), load_backend=load_backend)

    @app.get("/health")
    def health() -> dict[str, HealthStatus]:
        status = detector.status

        return {
            "data": HealthStatus(
                status="ok" if status.ready else "degraded",
                detector=status.detector,
                model_version=status.model_version,
                reason=status.reason,
            )
        }

    @app.post("/v1/predict", response_model=PredictionResponse)
    async def predict(
        image: Annotated[UploadFile, File()],
        panorama_node_id: Annotated[int, Form(gt=0)],
        camera_yaw: Annotated[float, Form()],
        camera_pitch: Annotated[float, Form()],
        camera_fov: Annotated[float, Form()],
    ) -> PredictionResponse:
        image_bytes = await image.read()

        if not image.content_type or not image.content_type.startswith("image/") or not is_valid_image(image_bytes):
            raise HTTPException(status_code=415, detail="image must be a valid image file")

        # Inference is CPU-bound and takes far longer than the request handling around it, so
        # it runs off the event loop and concurrent predictions are not serialised behind one.
        try:
            prediction = await run_in_threadpool(detector.predict, image_bytes)
        except ModelUnavailable as unavailable:
            # The service stays up while its model is unusable; the caller gets one
            # controlled error naming the cause, not a hung request or a stack trace.
            raise HTTPException(status_code=503, detail=str(unavailable))

        return PredictionResponse.model_validate(prediction)

    return app
