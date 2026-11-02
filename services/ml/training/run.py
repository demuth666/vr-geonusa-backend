"""Orchestration for one versioned training run.

The dataset is verified first, training happens in a staging directory, and only a
finished artifact and its run record are moved into the ignored artifacts area. Nothing
here imports the runtime API or touches application PostgreSQL.
"""

import shutil
import tempfile
from dataclasses import dataclass
from pathlib import Path

from dataset.hashing import file_digest
from dataset.manifest import write_json
from training.config import TrainingConfig, artifact_directory
from training.errors import TrainingError
from training.split import VerifiedDataset, verify_dataset
from training.trainer import Trainer, TrainingRequest, UltralyticsTrainer

ARTIFACT_FILE = "best.pt"
RUN_RECORD_FILE = "run-record.json"
RUN_RECORD_SCHEMA_VERSION = 1


@dataclass(frozen=True)
class RunRecord:
    """Everything needed to attribute, reproduce, or re-run one model artifact."""

    model_version: str
    base_checkpoint: str
    base_checkpoint_sha256: str
    seed: int
    epochs: int
    image_size: int
    split_seed: int
    split_ratios: dict[str, float]
    dataset_digest: str
    content_digest: str
    split_digest: str
    class_catalog: tuple[str, ...]
    artifact_path: str
    artifact_sha256: str
    artifact_bytes: int

    def to_dict(self) -> dict[str, object]:
        return {
            "schema_version": RUN_RECORD_SCHEMA_VERSION,
            "model_version": self.model_version,
            "base_checkpoint": self.base_checkpoint,
            "base_checkpoint_sha256": self.base_checkpoint_sha256,
            "seed": self.seed,
            "epochs": self.epochs,
            "image_size": self.image_size,
            "split_seed": self.split_seed,
            "split_ratios": dict(self.split_ratios),
            "dataset_digest": self.dataset_digest,
            "content_digest": self.content_digest,
            "split_digest": self.split_digest,
            "class_catalog": list(self.class_catalog),
            "artifact_path": self.artifact_path,
            "artifact_sha256": self.artifact_sha256,
            "artifact_bytes": self.artifact_bytes,
        }


def run_training(
    dataset: Path,
    config: TrainingConfig,
    *,
    artifacts_root: Path | None = None,
    trainer: Trainer | None = None,
    force: bool = False,
) -> RunRecord:
    """Verifies the dataset, trains once, then publishes the artifact and run record.

    Raises `TrainingError` before training starts when the dataset is leaked, changed, or
    incomplete, or when this model version already has an artifact and `force` is not set.
    """
    verified = verify_dataset(dataset)
    checkpoint = _checkpoint(config.base_checkpoint)
    checkpoint_sha256 = _sha256(checkpoint)

    output = artifact_directory(config.model_version, artifacts_root)
    _check_output(output, force, dataset=verified.root)

    trainer = trainer or UltralyticsTrainer()
    output.parent.mkdir(parents=True, exist_ok=True)
    staging = Path(tempfile.mkdtemp(prefix=f".{output.name}.", dir=output.parent))

    try:
        artifact = staging / ARTIFACT_FILE
        trainer.train(
            TrainingRequest(
                data_yaml=verified.data_yaml,
                base_checkpoint=checkpoint,
                artifact=artifact,
                workspace=staging / "workspace",
                epochs=config.epochs,
                image_size=config.image_size,
                seed=config.seed,
            )
        )
        _check_artifact(artifact)

        record = _record(
            verified,
            config,
            checkpoint=checkpoint,
            checkpoint_sha256=checkpoint_sha256,
            artifact=artifact,
            artifact_path=(output / ARTIFACT_FILE).resolve(),
        )
        write_json(record.to_dict(), staging / RUN_RECORD_FILE)
        _publish(staging, output)
    except BaseException:
        shutil.rmtree(staging, ignore_errors=True)
        raise

    return record


def _record(
    verified: VerifiedDataset,
    config: TrainingConfig,
    *,
    checkpoint: Path,
    checkpoint_sha256: str,
    artifact: Path,
    artifact_path: Path,
) -> RunRecord:
    return RunRecord(
        model_version=config.model_version,
        base_checkpoint=str(checkpoint),
        base_checkpoint_sha256=checkpoint_sha256,
        seed=config.seed,
        epochs=config.epochs,
        image_size=config.image_size,
        split_seed=verified.seed,
        split_ratios=verified.ratios,
        dataset_digest=verified.dataset_digest,
        content_digest=verified.content_digest,
        split_digest=verified.split_digest,
        class_catalog=verified.class_catalog,
        artifact_path=str(artifact_path),
        artifact_sha256=_sha256(artifact),
        artifact_bytes=artifact.stat().st_size,
    )


def _checkpoint(path: Path) -> Path:
    resolved = Path(path).resolve()
    if not resolved.is_file():
        raise TrainingError(
            f"base checkpoint '{path}' is not a file; fetch it first or pass "
            "--base-checkpoint"
        )

    return resolved


def _check_artifact(artifact: Path) -> None:
    if not artifact.is_file():
        raise TrainingError(f"the trainer did not write an artifact to '{artifact}'")
    if artifact.stat().st_size == 0:
        raise TrainingError(f"the trainer wrote an empty artifact to '{artifact}'")


def _check_output(output: Path, force: bool, *, dataset: Path) -> None:
    resolved = output.resolve()
    dataset_root = dataset.resolve()

    if (
        resolved == dataset_root
        or resolved.is_relative_to(dataset_root)
        or dataset_root.is_relative_to(resolved)
    ):
        raise TrainingError(
            f"artifact path '{output}' overlaps the dataset '{dataset}'; choose a "
            "separate artifacts directory"
        )

    if output.is_symlink():
        raise TrainingError(
            f"artifact directory '{output}' is a symbolic link; choose a real directory"
        )

    if output.exists() and not output.is_dir():
        raise TrainingError(f"artifact path '{output}' exists and is not a directory")

    if output.is_dir() and any(output.iterdir()) and not force:
        raise TrainingError(
            f"model version '{output.name}' already has an artifact at '{output}'; "
            "pass --force to replace it"
        )


def _publish(staging: Path, output: Path) -> None:
    """Moves the finished run into place, restoring the previous one if the move fails."""
    backup: Path | None = None

    if output.exists():
        backup = Path(
            tempfile.mkdtemp(prefix=f".{output.name}.previous.", dir=output.parent)
        )
        backup.rmdir()
        output.replace(backup)

    try:
        staging.replace(output)
    except BaseException:
        if backup is not None and backup.exists():
            backup.replace(output)
        raise
    else:
        if backup is not None:
            shutil.rmtree(backup, ignore_errors=True)


def _sha256(path: Path) -> str:
    return f"sha256:{file_digest(path)}"
