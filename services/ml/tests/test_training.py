import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

import pytest

import training.run as run_module
from dataset.split import SPLITS, SplitSummary, split_digest
from dataset_support import materialize_dataset
from training import (
    ARTIFACT_FILE,
    RUN_RECORD_FILE,
    TrainingConfig,
    TrainingError,
    artifact_directory,
    default_artifacts_root,
    default_base_checkpoint,
    run_training,
)

SERVICES_ML = Path(__file__).resolve().parents[1]
VERSION = "borobudur-detector-v1"


class FakeTrainer:
    """A substituted trainer: no Ultralytics, no training job, just an artifact."""

    def __init__(self, payload: bytes = b"trained weights") -> None:
        self.payload = payload
        self.requests: list[object] = []

    def train(self, request) -> None:
        self.requests.append(request)
        request.artifact.parent.mkdir(parents=True, exist_ok=True)
        request.artifact.write_bytes(self.payload)


@pytest.fixture
def checkpoint(tmp_path: Path) -> Path:
    path = tmp_path / "yolov8n.pt"
    path.write_bytes(b"base checkpoint bytes")

    return path


def config(checkpoint: Path, **overrides: object) -> TrainingConfig:
    settings: dict[str, object] = {
        "model_version": VERSION,
        "base_checkpoint": checkpoint,
        "epochs": 3,
        "seed": 7,
        "image_size": 320,
        **overrides,
    }

    return TrainingConfig(**settings)


def record_of(artifacts: Path) -> dict[str, object]:
    return json.loads(
        (artifacts / VERSION / RUN_RECORD_FILE).read_text(encoding="utf-8")
    )


def split_summaries(payload: dict[str, object]) -> dict[str, SplitSummary]:
    return {
        split: SplitSummary(
            panoramas=tuple(entry["panoramas"]),
            images=tuple(entry["images"]),
            counts=dict(entry["counts"]),
            class_distribution=dict(entry["class_distribution"]),
        )
        for split, entry in payload["splits"].items()
    }


def rewrite_split_manifest(dataset: Path, payload: dict[str, object]) -> None:
    """Writes a tampered manifest back with a digest that matches the tampered record."""
    payload["digest"] = split_digest(
        payload["dataset_digest"],
        payload["seed"],
        payload["ratios"],
        split_summaries(payload),
        payload["content_digest"],
    )
    (dataset / "split-manifest.json").write_text(
        json.dumps(payload, indent=2) + "\n", encoding="utf-8"
    )


def test_a_run_publishes_a_versioned_artifact_and_a_reproducible_record(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, split = materialize_dataset(tmp_path)
    artifacts = tmp_path / "artifacts"
    trainer = FakeTrainer()

    record = run_training(
        dataset, config(checkpoint), artifacts_root=artifacts, trainer=trainer
    )

    output = artifacts / VERSION
    assert (output / ARTIFACT_FILE).read_bytes() == b"trained weights"
    payload = record_of(artifacts)
    assert payload == record.to_dict()

    assert payload["schema_version"] == 1
    assert payload["model_version"] == VERSION
    assert payload["base_checkpoint"] == str(checkpoint.resolve())
    assert payload["base_checkpoint_sha256"] == (
        "sha256:" + hashlib.sha256(checkpoint.read_bytes()).hexdigest()
    )
    assert (payload["seed"], payload["epochs"], payload["image_size"]) == (7, 3, 320)
    assert payload["split_seed"] == split.seed
    assert payload["split_ratios"] == split.ratios
    assert payload["dataset_digest"] == split.dataset_digest
    assert payload["content_digest"] == split.content_digest
    assert payload["split_digest"] == split.digest
    assert payload["class_catalog"] == list(split.class_catalog)
    assert payload["artifact_path"] == str((output / ARTIFACT_FILE).resolve())
    assert payload["artifact_sha256"] == (
        "sha256:" + hashlib.sha256(b"trained weights").hexdigest()
    )
    assert payload["artifact_bytes"] == len(b"trained weights")

    assert len(trainer.requests) == 1
    request = trainer.requests[0]
    assert request.data_yaml == dataset / "data.yaml"
    assert request.base_checkpoint == checkpoint.resolve()
    assert request.epochs == 3
    assert request.image_size == 320
    assert request.seed == 7


def test_an_unchanged_dataset_and_configuration_reproduce_the_record(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    artifacts = tmp_path / "artifacts"

    first = run_training(
        dataset, config(checkpoint), artifacts_root=artifacts, trainer=FakeTrainer()
    )
    second = run_training(
        dataset,
        config(checkpoint),
        artifacts_root=artifacts,
        trainer=FakeTrainer(),
        force=True,
    )

    assert first.to_dict() == second.to_dict()
    assert first.dataset_digest == second.dataset_digest
    assert first.split_digest == second.split_digest


def test_panorama_leakage_is_refused_before_training_starts(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    manifest_path = dataset / "split-manifest.json"
    payload = json.loads(manifest_path.read_text(encoding="utf-8"))

    val_panorama = payload["splits"]["val"]["panoramas"][0]
    payload["splits"]["train"]["panoramas"].append(val_panorama)
    payload["splits"]["train"]["counts"]["panoramas"] += 1
    payload["counts"]["panoramas"] += 1
    rewrite_split_manifest(dataset, payload)

    artifacts = tmp_path / "artifacts"
    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="spans splits"):
        run_training(
            dataset, config(checkpoint), artifacts_root=artifacts, trainer=trainer
        )

    assert trainer.requests == []
    assert not (artifacts / VERSION).exists()


def test_a_manifest_whose_digest_does_not_match_is_refused(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    manifest_path = dataset / "split-manifest.json"
    payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    payload["seed"] += 1
    manifest_path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")

    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="digest"):
        run_training(
            dataset,
            config(checkpoint),
            artifacts_root=tmp_path / "artifacts",
            trainer=trainer,
        )

    assert trainer.requests == []


def test_a_dataset_that_no_longer_matches_the_manifest_is_refused(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, split = materialize_dataset(tmp_path)
    placed = {
        image: name for name, summary in split.splits.items() for image in summary.images
    }
    crop = split.splits["train"].images[0]
    (dataset / "images" / placed[crop] / f"{crop}.png").unlink()

    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="missing image"):
        run_training(
            dataset,
            config(checkpoint),
            artifacts_root=tmp_path / "artifacts",
            trainer=trainer,
        )

    assert trainer.requests == []


def test_a_dataset_whose_crop_bytes_changed_is_refused(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, split = materialize_dataset(tmp_path)
    placed = {
        image: name for name, summary in split.splits.items() for image in summary.images
    }
    crop = split.splits["train"].images[0]
    (dataset / "images" / placed[crop] / f"{crop}.png").write_bytes(b"other pixels")

    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="content digest"):
        run_training(
            dataset,
            config(checkpoint),
            artifacts_root=tmp_path / "artifacts",
            trainer=trainer,
        )

    assert trainer.requests == []


def test_a_data_yaml_that_points_elsewhere_is_refused(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    data_yaml = dataset / "data.yaml"
    lines = [
        f'path: "{(tmp_path / "elsewhere").resolve().as_posix()}"'
        if line.startswith("path:")
        else line
        for line in data_yaml.read_text(encoding="utf-8").splitlines()
    ]
    data_yaml.write_text("\n".join(lines) + "\n", encoding="utf-8")

    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="points at"):
        run_training(
            dataset,
            config(checkpoint),
            artifacts_root=tmp_path / "artifacts",
            trainer=trainer,
        )

    assert trainer.requests == []


def test_a_data_yaml_with_other_class_names_is_refused(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    data_yaml = dataset / "data.yaml"
    data_yaml.write_text(
        data_yaml.read_text(encoding="utf-8").replace('0: "stupa"', '0: "other"'),
        encoding="utf-8",
    )

    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="class names"):
        run_training(
            dataset,
            config(checkpoint),
            artifacts_root=tmp_path / "artifacts",
            trainer=trainer,
        )

    assert trainer.requests == []


def test_an_artifacts_root_inside_the_dataset_is_refused(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="overlaps the dataset"):
        run_training(
            dataset,
            config(checkpoint),
            artifacts_root=dataset / "artifacts",
            trainer=trainer,
        )

    assert trainer.requests == []


def test_an_existing_artifact_needs_force_to_be_replaced(
    tmp_path: Path, checkpoint: Path
) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    artifacts = tmp_path / "artifacts"
    run_training(
        dataset, config(checkpoint), artifacts_root=artifacts, trainer=FakeTrainer()
    )

    replacement = FakeTrainer(b"replacement weights")
    with pytest.raises(TrainingError, match="--force"):
        run_training(
            dataset,
            config(checkpoint),
            artifacts_root=artifacts,
            trainer=replacement,
        )

    assert replacement.requests == []
    assert (artifacts / VERSION / ARTIFACT_FILE).read_bytes() == b"trained weights"

    record = run_training(
        dataset,
        config(checkpoint),
        artifacts_root=artifacts,
        trainer=replacement,
        force=True,
    )

    assert (artifacts / VERSION / ARTIFACT_FILE).read_bytes() == b"replacement weights"
    assert record.artifact_sha256 == (
        "sha256:" + hashlib.sha256(b"replacement weights").hexdigest()
    )


def test_a_missing_base_checkpoint_is_refused(tmp_path: Path) -> None:
    dataset, _ = materialize_dataset(tmp_path)
    trainer = FakeTrainer()

    with pytest.raises(TrainingError, match="base checkpoint"):
        run_training(
            dataset,
            config(tmp_path / "missing.pt"),
            artifacts_root=tmp_path / "artifacts",
            trainer=trainer,
        )

    assert trainer.requests == []


@pytest.mark.parametrize(
    "version", ["", " v1", "v1 ", ".hidden", "../escape", "a/b", "a\\b", "v1:2"]
)
def test_a_model_version_must_be_one_directory_name(
    version: str, checkpoint: Path
) -> None:
    with pytest.raises(TrainingError, match="model version"):
        TrainingConfig(model_version=version, base_checkpoint=checkpoint)


def test_the_default_artifacts_area_is_the_ignored_artifacts_directory() -> None:
    assert default_artifacts_root().parts[-1] == "artifacts"
    assert artifact_directory(VERSION).parent == default_artifacts_root()
    assert artifact_directory(VERSION).parts[-1] == VERSION


def test_the_default_checkpoint_lives_in_the_ignored_artifacts_area() -> None:
    assert default_base_checkpoint().parent == default_artifacts_root()
    assert default_base_checkpoint().name == "yolov8n.pt"


def test_the_cli_runs_training_and_names_what_it_wrote(
    tmp_path: Path,
    checkpoint: Path,
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture[str],
) -> None:
    from training.__main__ import main

    dataset, _ = materialize_dataset(tmp_path)
    trainer = FakeTrainer()
    monkeypatch.setattr(run_module, "UltralyticsTrainer", lambda: trainer)

    exit_code = main(
        [
            "--dataset",
            str(dataset),
            "--model-version",
            VERSION,
            "--base-checkpoint",
            str(checkpoint),
            "--epochs",
            "2",
            "--seed",
            "11",
            "--image-size",
            "128",
            "--artifacts",
            str(tmp_path / "artifacts"),
        ]
    )

    assert exit_code == 0
    payload = record_of(tmp_path / "artifacts")
    assert (payload["epochs"], payload["seed"], payload["image_size"]) == (2, 11, 128)
    assert VERSION in capsys.readouterr().out


def test_the_cli_requires_an_explicit_model_version(tmp_path: Path) -> None:
    from training.__main__ import main

    with pytest.raises(SystemExit) as raised:
        main(["--dataset", str(tmp_path)])

    assert raised.value.code == 2


def test_the_cli_refuses_to_overwrite_without_force(
    tmp_path: Path,
    checkpoint: Path,
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture[str],
) -> None:
    from training.__main__ import main

    dataset, _ = materialize_dataset(tmp_path)
    monkeypatch.setattr(run_module, "UltralyticsTrainer", lambda: FakeTrainer())
    arguments = [
        "--dataset",
        str(dataset),
        "--model-version",
        VERSION,
        "--base-checkpoint",
        str(checkpoint),
        "--artifacts",
        str(tmp_path / "artifacts"),
    ]

    assert main(arguments) == 0
    capsys.readouterr()

    assert main(arguments) == 1
    assert "--force" in capsys.readouterr().err


def test_training_never_imports_the_runtime_api() -> None:
    script = (
        "import sys, training; "
        "print(sorted(m for m in sys.modules "
        "if m == 'app' or m.startswith(('app.', 'fastapi'))))"
    )

    result = subprocess.run(
        [sys.executable, "-c", script],
        cwd=SERVICES_ML,
        capture_output=True,
        text=True,
        env={**os.environ, "PYTHONPATH": str(SERVICES_ML)},
    )

    assert result.returncode == 0, result.stderr
    assert result.stdout.strip() == "[]"


def test_the_split_manifest_reader_covers_every_split(tmp_path: Path) -> None:
    dataset, _ = materialize_dataset(tmp_path)

    from training import verify_dataset

    verified = verify_dataset(dataset)

    assert tuple(verified.manifest.splits) == SPLITS
    assert verified.data_yaml == dataset / "data.yaml"
