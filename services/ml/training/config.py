"""The pinned configuration that defines one training run.

Every value that changes the resulting artifact is explicit here, so the run record can
name it and a later run can reproduce it.
"""

from dataclasses import dataclass, field
from pathlib import Path

from repository import repository_root
from training.errors import TrainingError

DEFAULT_EPOCHS = 50
DEFAULT_SEED = 42
DEFAULT_IMAGE_SIZE = 640
DEFAULT_BASE_CHECKPOINT = "yolov8n.pt"

_INVALID_NAME_CHARACTERS = frozenset('<>:"/\\|?*')


def default_base_checkpoint() -> Path:
    """The checkpoint the ignored artifacts area already holds, by default."""
    return default_artifacts_root() / DEFAULT_BASE_CHECKPOINT


@dataclass(frozen=True)
class TrainingConfig:
    """What to train, from which checkpoint, and for how long."""

    model_version: str
    base_checkpoint: Path = field(default_factory=default_base_checkpoint)
    epochs: int = DEFAULT_EPOCHS
    seed: int = DEFAULT_SEED
    image_size: int = DEFAULT_IMAGE_SIZE

    def __post_init__(self) -> None:
        _validate_model_version(self.model_version)
        _positive_int("epochs", self.epochs)
        _positive_int("image_size", self.image_size)

        if isinstance(self.seed, bool) or not isinstance(self.seed, int):
            raise TrainingError(f"the training seed must be an integer, got {self.seed!r}")

        object.__setattr__(self, "base_checkpoint", Path(self.base_checkpoint))


def default_artifacts_root() -> Path:
    """The repository's ignored artifacts directory, where runs are written by default."""
    return repository_root() / "artifacts"


def artifact_directory(model_version: str, root: Path | None = None) -> Path:
    """The directory one model version owns under the artifacts root."""
    _validate_model_version(model_version)

    return (Path(root) if root is not None else default_artifacts_root()) / model_version


def _validate_model_version(model_version: str) -> None:
    """A version names exactly one directory, so a run can never escape the artifact area."""
    if not isinstance(model_version, str):
        raise TrainingError(
            f"the model version must be a string, got {type(model_version).__name__}"
        )

    candidate = Path(model_version)
    if (
        not model_version
        or model_version != model_version.strip()
        or model_version.startswith(".")
        or _INVALID_NAME_CHARACTERS & set(model_version)
        or candidate.is_absolute()
        or candidate.name != model_version
    ):
        raise TrainingError(
            "the model version must be an explicit single directory name such as "
            f"'borobudur-detector-v1', got {model_version!r}"
        )


def _positive_int(name: str, value: int) -> None:
    if isinstance(value, bool) or not isinstance(value, int) or value <= 0:
        raise TrainingError(f"{name} must be a positive integer, got {value!r}")
