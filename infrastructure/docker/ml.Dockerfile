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

# Ultralytics imports OpenCV, which links against C libraries a slim image leaves out, so
# the runtime cannot load the model without them. They are installed only for the
# model-serving image, and after the runtime so changing them does not invalidate it.
RUN if [ "$INSTALL_YOLO" = "true" ]; then \
        apt-get update \
        && apt-get install --no-install-recommends -y libgl1 libglib2.0-0 libxcb1 \
        && rm -rf /var/lib/apt/lists/*; \
    fi

COPY services/ml/app ./app
COPY services/ml/preprocessing ./preprocessing
# Dataset preparation is not imported by the service. It is copied so the image's own
# pytest suite stays runnable, as the README's verification steps expect.
COPY services/ml/dataset ./dataset
COPY services/ml/tests ./tests
COPY services/ml/pytest.ini ./

RUN useradd --create-home app \
    && chown -R app:app /app

USER app

EXPOSE 8001

# The app is built by the factory so that only the serving process loads the model: an
# imported module (tests, tooling) never pays for it.
CMD ["uvicorn", "app.main:create_app", "--factory", "--host=0.0.0.0", "--port=8001"]
