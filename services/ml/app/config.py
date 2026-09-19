import os
from collections.abc import Mapping
from dataclasses import dataclass
from enum import StrEnum
from pathlib import Path


class DetectorKind(StrEnum):
    """Detector implementations the service can be configured to serve."""

    DUMMY = "dummy"
    YOLO = "yolo"


@dataclass(frozen=True)
class ModelSettings:
    """Everything needed to load one model artifact and run inference with it."""

    artifact_path: Path
    model_version: str
    input_size: int
    confidence_threshold: float

    @classmethod
    def from_environment(cls, environment: Mapping[str, str]) -> "ModelSettings":
        return cls(
            artifact_path=_artifact_path(_configured(environment, "ML_MODEL_PATH")),
            model_version=_model_version(_configured(environment, "ML_MODEL_VERSION")),
            input_size=_positive_int("ML_IMAGE_SIZE", _configured(environment, "ML_IMAGE_SIZE"), 640),
            confidence_threshold=_probability(
                "ML_CONFIDENCE_THRESHOLD", _configured(environment, "ML_CONFIDENCE_THRESHOLD"), 0.25
            ),
        )


@dataclass(frozen=True)
class Settings:
    """Service configuration, read once at startup."""

    detector: DetectorKind
    model: ModelSettings | None

    @classmethod
    def from_environment(cls, environment: Mapping[str, str] = os.environ) -> "Settings":
        detector = _detector(_configured(environment, "ML_DETECTOR") or DetectorKind.DUMMY)

        return cls(
            detector=detector,
            # Only a detector that loads an artifact is given model settings, so a dummy
            # deployment never depends on a model path, version, or tuning value.
            model=None if detector is DetectorKind.DUMMY else ModelSettings.from_environment(environment),
        )


def _configured(environment: Mapping[str, str], name: str) -> str | None:
    """Reads a setting, treating unset and blank alike.

    Compose passes a variable it was not given as an empty string, which is not a value.
    """
    value = environment.get(name, "").strip()

    return value or None


def _detector(configured: str) -> DetectorKind:
    try:
        return DetectorKind(configured)
    except ValueError as error:
        supported = ", ".join(kind.value for kind in DetectorKind)
        raise ValueError(f"ML_DETECTOR must be one of {supported}, got {configured!r}") from error


def _artifact_path(configured: str | None) -> Path:
    if not configured:
        raise ValueError("ML_MODEL_PATH must point at the model artifact the configured detector loads")

    path = Path(configured)

    # The artifact is loaded from disk at startup. Refuse to start on a path that is not
    # there, instead of letting a model library fetch a pretrained checkpoint instead.
    if not path.is_file():
        raise ValueError(f"ML_MODEL_PATH does not point at a file: {configured}")

    return path


def _model_version(configured: str | None) -> str:
    if not configured:
        raise ValueError("ML_MODEL_VERSION must name the model artifact, so predictions stay attributable")

    return configured


def _positive_int(name: str, configured: str | None, default: int) -> int:
    if configured is None:
        return default

    try:
        value = int(configured)
    except ValueError as error:
        raise ValueError(f"{name} must be an integer, got {configured!r}") from error

    if value <= 0:
        raise ValueError(f"{name} must be positive, got {value}")

    return value


def _probability(name: str, configured: str | None, default: float) -> float:
    if configured is None:
        return default

    try:
        value = float(configured)
    except ValueError as error:
        raise ValueError(f"{name} must be a number, got {configured!r}") from error

    if not 0.0 <= value <= 1.0:
        raise ValueError(f"{name} must be between 0 and 1, got {value}")

    return value
