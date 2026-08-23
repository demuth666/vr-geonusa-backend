FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

COPY services/ml/requirements.txt services/ml/requirements-dev.txt ./
RUN pip install --no-cache-dir -r requirements-dev.txt

COPY services/ml/app ./app
COPY services/ml/tests ./tests
COPY services/ml/pytest.ini ./

RUN useradd --create-home app \
    && chown -R app:app /app

USER app

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host=0.0.0.0", "--port=8001"]
