"""Command line entry point for training a versioned detector from a split dataset."""

import argparse
import sys
from collections.abc import Sequence
from pathlib import Path

from training import (
    DEFAULT_EPOCHS,
    DEFAULT_IMAGE_SIZE,
    DEFAULT_SEED,
    RUN_RECORD_FILE,
    TrainingConfig,
    TrainingError,
    default_base_checkpoint,
    run_training,
)


def main(argv: Sequence[str] | None = None) -> int:
    arguments = _parser().parse_args(argv)

    try:
        record = run_training(
            arguments.dataset,
            TrainingConfig(
                model_version=arguments.model_version,
                base_checkpoint=arguments.base_checkpoint
                or default_base_checkpoint(),
                epochs=arguments.epochs,
                seed=arguments.seed,
                image_size=arguments.image_size,
            ),
            artifacts_root=arguments.artifacts,
            force=arguments.force,
        )
    except TrainingError as error:
        print(f"error: {error}", file=sys.stderr)
        return 1
    except OSError as error:
        print(f"error: cannot write the trained model: {error}", file=sys.stderr)
        return 1

    print(
        f"Trained model version '{record.model_version}' "
        f"(epochs={record.epochs}, seed={record.seed}, "
        f"image_size={record.image_size}) from {record.dataset_digest}; wrote "
        f"{record.artifact_path} and "
        f"{Path(record.artifact_path).parent / RUN_RECORD_FILE}."
    )

    return 0


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python -m training",
        description=(
            "Train a versioned detector from a materialized, leakage-free split dataset "
            "and write the artifact with a reproducible run record into the ignored "
            "artifacts area."
        ),
    )
    parser.add_argument(
        "--dataset",
        type=Path,
        required=True,
        help=(
            "materialized dataset directory containing images/, labels/, data.yaml, "
            "and split-manifest.json"
        ),
    )
    parser.add_argument(
        "--model-version",
        required=True,
        help=(
            "explicit version this run's artifact is published under; also its "
            "directory name under the artifacts root"
        ),
    )
    parser.add_argument(
        "--base-checkpoint",
        type=Path,
        default=None,
        help=(
            "checkpoint training starts from, recorded with its checksum "
            f"(default: artifacts/{default_base_checkpoint().name} under the repository)"
        ),
    )
    parser.add_argument(
        "--epochs",
        type=int,
        default=DEFAULT_EPOCHS,
        help=f"training epochs (default: {DEFAULT_EPOCHS})",
    )
    parser.add_argument(
        "--seed",
        type=int,
        default=DEFAULT_SEED,
        help=f"training seed (default: {DEFAULT_SEED})",
    )
    parser.add_argument(
        "--image-size",
        type=int,
        default=DEFAULT_IMAGE_SIZE,
        help=f"training image size (default: {DEFAULT_IMAGE_SIZE})",
    )
    parser.add_argument(
        "--artifacts",
        type=Path,
        default=None,
        help=(
            "artifacts root to publish under (default: the repository's ignored "
            "artifacts/ directory)"
        ),
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="replace the artifact already published for this model version",
    )

    return parser


if __name__ == "__main__":
    raise SystemExit(main())
