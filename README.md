# VR-GeoNusa

Backend and Machine Learning foundation for the Borobudur-only VR-GeoNusa project.

## Requirements

- Docker Engine
- Docker Compose v2

PHP, Composer, Python, PostgreSQL, Redis, and MinIO run inside Docker.

## Bootstrap

```bash
cp .env.example .env
cp apps/api/.env.example apps/api/.env
docker compose build
docker compose run --rm --no-deps api php artisan key:generate --show
docker compose up -d --wait
docker compose exec -T api php artisan migrate --force
```

Replace the placeholder passwords in `.env`, then copy the generated application key into
`APP_KEY` in `apps/api/.env` before starting the services.
Docker Compose creates the public development panorama bucket automatically before the API starts.

## Services

| Service | URL |
| --- | --- |
| Laravel API | <http://127.0.0.1:8000/api/v1/health> |
| Filament admin | <http://127.0.0.1:8000/admin> |
| ML service | <http://127.0.0.1:8001/health> |
| MinIO API | <http://127.0.0.1:9000> |
| MinIO console | <http://127.0.0.1:9001> |

Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `apps/api/.env`, then create or update
the first super-admin after running migrations:

```bash
docker compose run --rm api php artisan db:seed --class=AdminUserSeeder --force
```

## ML detector

The ML service serves either detector, selected by `ML_DETECTOR`:

- `dummy` (default) answers with a fixed prediction, so tests, CI, and environments
  without a model artifact keep working.
- `yolo` loads the artifact at `ML_MODEL_PATH` once at startup, before the service
  accepts traffic, and serves it on the same `/v1/predict` route and in the same
  response shape.

`/health` reports which detector the running service is serving and whether real
inference is available, without having to read the service's source:

```json
{"data": {"status": "ok", "detector": "yolo", "model_version": "yolov8n-coco-v1", "reason": null}}
```

### Run the real detector from a fresh clone

The model artifact is not committed to the repository. Fetch the pinned release asset
into `artifacts/`, the directory the ML container mounts read-only, and verify it
against its checksum:

```bash
docker compose --profile artifacts run --rm ml-artifacts
```

Then enable the detector in `.env` — a fresh `.env` already names the artifact the fetch
installed — and rebuild, because the image carries Ultralytics and PyTorch, a
multi-gigabyte install, only when asked:

```bash
INSTALL_YOLO=true
ML_DETECTOR=yolo
ML_MODEL_PATH=/app/artifacts/yolov8n.pt
ML_MODEL_VERSION=yolov8n-coco-v1

docker compose build ml
docker compose up -d --wait
```

`ML_MODEL_VERSION` is reported on every prediction and recorded by Laravel against the
inference run, so it must identify the artifact. The fetch is reproducible: the URL and
SHA-256 in `.env` pin the exact bytes, an artifact that is already there is kept, and
one that does not match the pin is never silently replaced. To serve a different
checkpoint, put it in `artifacts/` and point `ML_MODEL_PATH` and `ML_MODEL_VERSION` at
it.

### When the model is missing

An artifact that is missing, unreadable, or corrupt does not stop the service. It still
starts and serves `/health`, reporting the degraded state and the reason:

```json
{"data": {"status": "degraded", "detector": "yolo", "model_version": null, "reason": "ML_MODEL_PATH does not point at a file: /app/artifacts/yolov8n.pt"}}
```

Prediction requests answer `503` with a controlled error, which Laravel records as a
failed inference run and returns to the client as `502`. Nothing crashes, hangs, or
answers with a stack trace, and health keeps answering `200` while the process is up,
so a deployment without a usable artifact stays observable instead of crash-looping.
The dummy detector remains the supported way to run the stack without an artifact at
all.

## Verify

```bash
docker compose config --quiet
docker compose exec -T api php artisan cache:clear
docker compose exec -T api php artisan test
docker compose exec -T ml pytest
curl --fail http://127.0.0.1:8000/api/v1/health
curl --fail http://127.0.0.1:8001/health
curl --fail http://127.0.0.1:9000/minio/health/live
```

With the real detector enabled, the ML suite also exercises the artifact itself: a
prediction over Laravel's largest accepted upload has to answer within
`ML_SERVICE_TIMEOUT`, and a corrupt artifact has to degrade instead of stopping the
service. Both tests skip where the runtime or the artifact is absent.

Stop the services without deleting development data:

```bash
docker compose down
```
