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

Stop the services without deleting development data:

```bash
docker compose down
```
