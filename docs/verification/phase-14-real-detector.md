# Phase 14 — Real detector end-to-end through Laravel

Date: 2026-10-26

Issue: #32

This record proves that the real, stock YOLO detector already works through the
existing Laravel endpoint and persistence path. No Laravel application code,
migration, or OpenAPI change was made for this phase.

## Environment

- Composed stack started with `INSTALL_YOLO=true`, `ML_DETECTOR=yolo`, and
  `ML_MODEL_VERSION=yolov8n-coco-v1`.
- Artifact: `artifacts/yolov8n.pt`, the pinned `yolov8n.pt` release asset fetched by
  `infrastructure/scripts/fetch-model.sh`.
- The verification stack ran under Podman on alternate host ports, so it could run
  alongside the already-running development stack without touching its data.

ML service health while serving the real artifact:

```json
{"data":{"status":"ok","detector":"yolo","model_version":"yolov8n-coco-v1","reason":null}}
```

## Successful prediction through the existing endpoint

The request used the existing `POST /api/v1/learning-sessions/{id}/ml-predictions`
endpoint with a student session in the exploration phase, the session write token, and
the Ultralytics `bus.jpg` viewport. The bearer token and write token are redacted here.

```bash
curl -X POST "$API/api/v1/learning-sessions/1/ml-predictions" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <student-token>" \
  -H "X-Session-Write-Token: <session-write-token>" \
  -F "image=@bus.jpg;type=image/jpeg" \
  -F "panorama_node_id=1" \
  -F "camera_yaw=45.5" \
  -F "camera_pitch=-10" \
  -F "camera_fov=90"
```

Response: `201 Created` in `2.63 s`, well inside Laravel's configured
`ML_SERVICE_TIMEOUT=10` seconds.

```json
{
  "data": {
    "model_version": "yolov8n-coco-v1",
    "inference_ms": 825,
    "total_latency_ms": 1699,
    "detections": [
      {"class": "person", "confidence": 0.8962, "bounding_box": [671, 385, 810, 880], "heritage_object": null, "geometry_mappings": []},
      {"class": "person", "confidence": 0.883, "bounding_box": [222, 407, 344, 856], "heritage_object": null, "geometry_mappings": []},
      {"class": "person", "confidence": 0.8732, "bounding_box": [50, 398, 244, 905], "heritage_object": null, "geometry_mappings": []},
      {"class": "bus", "confidence": 0.8362, "bounding_box": [31, 231, 801, 778], "heritage_object": null, "geometry_mappings": []},
      {"class": "person", "confidence": 0.4296, "bounding_box": [0, 549, 59, 868], "heritage_object": null, "geometry_mappings": []}
    ]
  }
}
```

The response passed Laravel's existing response validation unchanged: model version,
integer inference latency, at most 100 detections, class keys, confidences in `0..1`,
and four-integer bounding boxes.

## Persisted inference run

Reading the same run back from the application database:

```json
{
  "run": {
    "id": 1,
    "status": "succeeded",
    "model_version": "yolov8n-coco-v1",
    "learning_session_id": 1,
    "panorama_node_id": 1,
    "camera_yaw": 45.5,
    "camera_pitch": -10,
    "camera_fov": 90,
    "inference_ms": 825,
    "total_latency_ms": 1699
  },
  "detections": [
    {"class_key": "person", "confidence": 0.8962, "bounding_box": [671, 385, 810, 880]},
    {"class_key": "person", "confidence": 0.883, "bounding_box": [222, 407, 344, 856]},
    {"class_key": "person", "confidence": 0.8732, "bounding_box": [50, 398, 244, 905]},
    {"class_key": "bus", "confidence": 0.8362, "bounding_box": [31, 231, 801, 778]},
    {"class_key": "person", "confidence": 0.4296, "bounding_box": [0, 549, 59, 868]}
  ],
  "class_mappings_for_version": 0,
  "session": {
    "phase": "exploration",
    "updated_at": "2026-10-26T12:47:57.000000Z",
    "activity_events": 0
  }
}
```

The stock detector's COCO keys have no authored `ml_class_mappings` for
`yolov8n-coco-v1`. They were still persisted and returned, and every detection stayed
unresolved with `heritage_object: null` and `geometry_mappings: []`. No Heritage Object
or Heritage Geometry Mapping link was fabricated.

The learning session did not advance or mutate: phase stayed `exploration`, its
`updated_at` did not move, its write-token hash did not change, and no activity event was
recorded.

## Non-gating failure path

While the same session was still in exploration, the ML container was stopped and the
same request was submitted again.

Response: `502` with the controlled frontend-facing error body:

```json
{"error":{"code":"SERVER_ERROR","message":"Server error."}}
```

The corresponding inference run was recorded as failed:

```json
{
  "run": {
    "id": 2,
    "status": "failed",
    "failure_reason": "connection_failed",
    "model_version": null,
    "camera_yaw": 45.5,
    "camera_pitch": -10,
    "camera_fov": 90,
    "inference_ms": null,
    "total_latency_ms": 2109
  },
  "session": {
    "phase": "exploration",
    "updated_at": "2026-10-26T12:47:57.000000Z",
    "activity_events": 0
  }
}
```

The ML container was restarted and `/health` returned to the real detector. The session
remained in exploration.

## Filament back-office

The existing `MlInferenceRunResource` index was requested with a valid super-admin
session: `GET /admin/ml-inference-runs` returned `200`, and the rendered page contained:

- `yolov8n-coco-v1` (the real model version),
- `person` and `bus` (the real detection class keys),
- `connection_failed` (the failed run).

The resource is read-only: inference runs and detections expose no create, edit, or
delete actions; this is covered by `MlBackOfficeTest`.

## Automated regression coverage

`apps/api/tests/Feature/MlServiceIntegrationTest.php` now contains a real-detector test
that runs only when the composed ML service reports the stock `yolov8n-coco-v1` COCO
detector and is healthy. It skips for the dummy detector or a different model version,
and a degraded `yolo` service fails instead of skipping. It sends
`tests/Fixtures/real-detector-viewport.jpg` through the existing endpoint and asserts:

- a real `person` detection is returned and persisted,
- the run names the real model version and learning session, with camera metadata and
  latencies,
- no class mappings exist for the stock model version and no detection is resolved,
- the response and database detections agree on class key, confidence, and bounding box,
- the learning session stays in exploration without changing its write token or
  `updated_at`,
- the run is visible in the Filament ML inference-run index.

The existing `MlPredictionTest` continues to cover the fake-service failure paths,
including recorded failed runs and controlled `502` responses.

## Commands and results

The manual end-to-end proof ran on an isolated Podman stack on alternate host ports,
using the real artifact. The full automated suites then ran against the current
composed API/ML stack at branch HEAD (`957ccf2`) with this change's test files in place.

Manual composed-stack proof:

```bash
podman compose up -d --no-build --wait
podman compose exec -T api php artisan migrate --force
podman compose exec -T api php artisan db:seed --force
curl ... /api/v1/learning-sessions/1/ml-predictions
podman stop vr-geonusa-podman-ml-1
curl ... /api/v1/learning-sessions/1/ml-predictions
podman compose start ml
```

Automated suites against the composed stack:

```bash
php artisan test --filter=MlServiceIntegrationTest
# 2 passed (77 assertions)

php artisan test --filter='MlPredictionTest|MlBackOfficeTest'
# 15 passed (204 assertions)

php artisan test
# 131 passed (1257 assertions)

pytest
# 70 passed
```

The Podman machine available here is limited to 2 GiB / 2 CPUs and its API image
predated this branch, so the full Laravel suite was run in the current API container of
the composed stack instead. The ML suite was run with the real detector on Podman as
well as the current stack.
