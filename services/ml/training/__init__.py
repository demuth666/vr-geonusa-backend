"""Versioned detector training from a materialized, leakage-free dataset.

This package is deliberately separate from the runtime API: it reads only what the split
stage wrote, trains with a pinned configuration, and publishes the artifact with a run
record into the ignored artifacts area. The runtime service never imports it.
"""

from training.config import (
    DEFAULT_BASE_CHECKPOINT,
    DEFAULT_EPOCHS,
    DEFAULT_IMAGE_SIZE,
    DEFAULT_SEED,
    TrainingConfig,
    artifact_directory,
    default_artifacts_root,
    default_base_checkpoint,
)
from training.errors import TrainingError
from training.run import (
    ARTIFACT_FILE,
    RUN_RECORD_FILE,
    RUN_RECORD_SCHEMA_VERSION,
    RunRecord,
    run_training,
)
from training.split import (
    SPLIT_MANIFEST_FILE,
    VerifiedDataset,
    read_split_manifest,
    verify_dataset,
)
from training.trainer import Trainer, TrainingRequest, UltralyticsTrainer

__all__ = [
    "ARTIFACT_FILE",
    "DEFAULT_BASE_CHECKPOINT",
    "DEFAULT_EPOCHS",
    "DEFAULT_IMAGE_SIZE",
    "DEFAULT_SEED",
    "RUN_RECORD_FILE",
    "RUN_RECORD_SCHEMA_VERSION",
    "SPLIT_MANIFEST_FILE",
    "RunRecord",
    "Trainer",
    "TrainingConfig",
    "TrainingError",
    "TrainingRequest",
    "UltralyticsTrainer",
    "VerifiedDataset",
    "artifact_directory",
    "default_artifacts_root",
    "default_base_checkpoint",
    "read_split_manifest",
    "run_training",
    "verify_dataset",
]
