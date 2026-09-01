from app.detector import DummyDetector


def test_dummy_detector_returns_the_versioned_contract() -> None:
    prediction = DummyDetector().predict(b"image bytes")

    assert prediction == {
        "model_version": "dummy-v1",
        "inference_ms": 10,
        "detections": [
            {
                "class": "stupa",
                "confidence": 0.95,
                "bounding_box": [10, 20, 100, 120],
            }
        ],
    }
