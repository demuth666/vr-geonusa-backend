from typing import Protocol


class Detector(Protocol):
    def predict(self, image: bytes) -> dict[str, object]: ...


class DummyDetector:
    def predict(self, image: bytes) -> dict[str, object]:
        return {
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


detector: Detector = DummyDetector()
