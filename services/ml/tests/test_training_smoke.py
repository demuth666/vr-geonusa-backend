"""Exercises a real Ultralytics training run where its runtime and a checkpoint exist.

The default test suite and CI install neither, so this skips there. Where they are
installed, one epoch on the tiny materialized dataset proves the orchestration publishes
an artifact Ultralytics can load and a run record that attributes it.
"""

import json
import os
from pathlib import Path

import pytest

from dataset_support import materialize_dataset
from training import (
    ARTIFACT_FILE,
    RUN_RECORD_FILE,
    TrainingConfig,
    default_base_checkpoint,
    run_training,
)


def installed_checkpoint() -> Path | None:
    """The base checkpoint this environment has, if any."""
    configured = os.environ.get("ML_TRAINING_CHECKPOINT")
    candidates = (
        [Path(configured)]
        if configured
        else [default_base_checkpoint(), Path("yolov8n.pt")]
    )

    return next((path for path in candidates if path.is_file()), None)


def test_a_real_training_run_writes_a_loadable_artifact_and_record(
    tmp_path: Path,
) -> None:
    pytest.importorskip("ultralytics")

    checkpoint = installed_checkpoint()
    if checkpoint is None:
        pytest.skip(
            "no base checkpoint is installed; fetch one with "
            "infrastructure/scripts/fetch-model.sh or set ML_TRAINING_CHECKPOINT"
        )

    dataset, split = materialize_dataset(tmp_path)
    artifacts = tmp_path / "artifacts"

    record = run_training(
        dataset,
        TrainingConfig(
            model_version="smoke-v1",
            base_checkpoint=checkpoint,
            epochs=1,
            seed=0,
            image_size=64,
        ),
        artifacts_root=artifacts,
        force=True,
    )

    artifact = Path(record.artifact_path)
    assert artifact == (artifacts / "smoke-v1" / ARTIFACT_FILE).resolve()
    assert artifact.is_file()
    assert artifact.stat().st_size > 0

    payload = json.loads(
        (artifact.parent / RUN_RECORD_FILE).read_text(encoding="utf-8")
    )
    assert payload["model_version"] == "smoke-v1"
    assert payload["dataset_digest"] == split.dataset_digest
    assert payload["content_digest"] == split.content_digest
    assert payload["split_digest"] == split.digest
    assert payload["class_catalog"] == list(split.class_catalog)
    assert payload["epochs"] == 1

    from ultralytics import YOLO

    loaded = YOLO(str(artifact))
    assert loaded is not None
