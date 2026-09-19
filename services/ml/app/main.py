from typing import Annotated

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel, Field
from starlette.concurrency import run_in_threadpool

from app.config import Settings
from app.detector import Detector, build_detector
from app.preprocessing import is_valid_image
from app.yolo_backend import load_yolo_backend

app = FastAPI(title="VR-GeoNusa ML Service")

# Built once, at startup: the configured detector loads its model artifact here and reuses
# it for every prediction. A configuration the service cannot serve stops it from starting.
detector: Detector = build_detector(Settings.from_environment(), load_backend=load_yolo_backend)


class Detection(BaseModel):
    class_: str = Field(alias="class")
    confidence: float
    bounding_box: list[int]


class PredictionResponse(BaseModel):
    model_version: str
    inference_ms: int
    detections: list[Detection]


@app.get("/health")
def health() -> dict[str, dict[str, str]]:
    return {"data": {"status": "ok"}}


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
    prediction = await run_in_threadpool(detector.predict, image_bytes)

    return PredictionResponse.model_validate(prediction)
