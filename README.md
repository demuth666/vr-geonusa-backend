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

## Dataset preparation

Phase 15 dataset tooling lives in `services/ml/dataset/`. The runtime service never
imports it, and it never reads an Inference Run image. It validates an external
YOLO-style export before any split or training work begins. The ML image copies the
package only so its own `pytest` suite can run; serving a prediction never touches it.

An export is a directory with this shape:

```text
export/
  images/                 # annotated crops
  labels/                 # <crop>.txt, one file per crop
  panoramas/              # one file per source panorama the export contains
  classes.txt             # one stable detector class key per line
  provenance.csv          # crop,source_panorama
```

- `classes.txt` line order defines the YOLO class index. Keys are detector class keys
  that Laravel resolves through ML Class Mappings to Heritage Objects; they are not
  Geometry Shapes.
- Each label line is an object-level bounding box in normalized YOLO form:
  `class_index x_center y_center width height`. Empty label files are valid negative
  samples, but the export as a whole must contain at least one object box.
- Identical crop pixels under different names are rejected: duplicates are leakage
  waiting to be split across panoramas.
- `provenance.csv` must name every crop exactly once and must only name panoramas
  declared in the `panoramas/` directory. `crop` is the crop filename without its
  extension; a `source_panorama` is a panorama filename without its extension. Grouping
  is never inferred from crop filenames or directories. The panorama files themselves
  are not opened; identity is all the split needs.

Validate an export and write the normalized manifest:

```bash
cd services/ml
python -m dataset validate path/to/export --output path/to/dataset-manifest.json
```

A valid export exits `0` and writes deterministic JSON with the class catalog, panorama
groups, crops and object boxes, per-class distribution, counts, and a `sha256` input
digest. An invalid export exits `1`, prints every offending crop or label with the rule
it broke, and writes no manifest.

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

The Laravel integration test also sends a real viewport through the stacked endpoint
when the ML service reports the stock `yolov8n-coco-v1` detector. It asserts the COCO
class keys are persisted and returned unresolved, and that the learning session stays
in exploration. With the dummy detector, or a different model version, it skips.

Stop the services without deleting development data:

```bash
docker compose down
```
