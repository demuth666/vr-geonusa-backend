from fastapi import FastAPI

app = FastAPI(title="VR-GeoNusa ML Service")


@app.get("/health")
def health() -> dict[str, dict[str, str]]:
    return {"data": {"status": "ok"}}

