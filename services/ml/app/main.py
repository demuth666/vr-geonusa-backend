from typing import Annotated

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel, ConfigDict, Field

from app.detector import detector
from app.preprocessing import is_valid_image

app = FastAPI(title="VR-GeoNusa ML Service")


class Detection(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

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

    return PredictionResponse.model_validate(detector.predict(image_bytes))
