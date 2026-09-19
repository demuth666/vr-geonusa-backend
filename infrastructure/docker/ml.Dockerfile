FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

COPY services/ml/requirements.txt services/ml/requirements-dev.txt services/ml/requirements-yolo.txt ./
RUN pip install --no-cache-dir -r requirements-dev.txt

# The YOLO detector and its PyTorch runtime are only installed when this build is asked to
# serve a model artifact, keeping the default image (and the dummy detector) small.
ARG INSTALL_YOLO=false
RUN if [ "$INSTALL_YOLO" = "true" ]; then pip install --no-cache-dir -r requirements-yolo.txt; fi

COPY services/ml/app ./app
COPY services/ml/tests ./tests
COPY services/ml/pytest.ini ./

RUN useradd --create-home app \
    && chown -R app:app /app

USER app

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host=0.0.0.0", "--port=8001"]
