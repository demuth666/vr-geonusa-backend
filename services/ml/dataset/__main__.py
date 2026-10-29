"""Command line entry point for validating and splitting a crop export."""

import argparse
import sys
from collections.abc import Sequence
from pathlib import Path

from dataset import (
    DEFAULT_DATASET_NAME,
    DEFAULT_RATIOS,
    DEFAULT_SEED,
    SPLITS,
    DatasetManifestError,
    DatasetValidationError,
    SplitError,
    default_output,
    prepare_export,
    read_manifest,
    split_dataset,
    write_manifest,
)


def main(argv: Sequence[str] | None = None) -> int:
    parser = _parser()
    arguments = parser.parse_args(argv)

    if arguments.command == "validate":
        return _validate(arguments.export, arguments.manifest)

    return _split(arguments)


def _validate(export: Path, manifest_path: Path) -> int:
    try:
        manifest = prepare_export(export)
    except DatasetValidationError as error:
        _print_issues(error)
        if manifest_path.exists():
            print(
                f"Existing manifest '{manifest_path}' was left untouched.",
                file=sys.stderr,
            )
        else:
            print("No manifest was written.", file=sys.stderr)
        return 1

    write_manifest(manifest, manifest_path)

    boxes = sum(len(crop.boxes) for crop in manifest.crops)
    panorama_count = len(manifest.panoramas)
    panorama_label = "panorama" if panorama_count == 1 else "panoramas"
    print(
        f"Validated {len(manifest.crops)} crops ({boxes} boxes) across "
        f"{panorama_count} source {panorama_label}; wrote {manifest_path}."
    )

    return 0


def _split(arguments: argparse.Namespace) -> int:
    try:
        output = arguments.output or default_output(
            arguments.name or DEFAULT_DATASET_NAME
        )
    except SplitError as error:
        print(f"error: {error}", file=sys.stderr)
        return 1

    try:
        recorded = read_manifest(arguments.manifest)
    except DatasetManifestError as error:
        print(error, file=sys.stderr)
        return 1

    try:
        result = split_dataset(
            recorded,
            arguments.export,
            output,
            seed=arguments.seed,
            ratios=arguments.ratios,
            force=arguments.force,
        )
    except DatasetValidationError as error:
        _print_issues(error)
        print("No dataset was split.", file=sys.stderr)
        return 1
    except SplitError as error:
        print(f"error: {error}", file=sys.stderr)
        return 1
    except OSError as error:
        print(f"error: cannot write the split: {error}", file=sys.stderr)
        return 1

    summary = ", ".join(
        f"{split}={result.splits[split].counts['images']}" for split in SPLITS
    )
    print(
        f"Split {result.counts['images']} crops ({result.counts['boxes']} boxes) "
        f"across {result.counts['panoramas']} source panoramas into {summary}; "
        f"wrote {output}."
    )

    return 0


def _print_issues(error: DatasetValidationError) -> None:
    for issue in error.issues:
        print(issue, file=sys.stderr)
    print(
        f"{len(error.issues)} validation issue(s) found.",
        file=sys.stderr,
    )


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python -m dataset",
        description=(
            "Validate an external YOLO-style crop export and split it by source "
            "panorama into training, validation, and test data."
        ),
    )
    commands = parser.add_subparsers(dest="command", required=True)

    validate = commands.add_parser(
        "validate",
        help="validate an export and write its manifest",
    )
    validate.add_argument(
        "export",
        type=Path,
        help=(
            "export directory containing images/, labels/, panoramas/, "
            "classes.txt, and provenance.csv"
        ),
    )
    validate.add_argument(
        "--output",
        "--manifest",
        dest="manifest",
        type=Path,
        required=True,
        help="path to write the normalized JSON manifest to",
    )

    split = commands.add_parser(
        "split",
        help="split a validated export into train, val, and test",
    )
    split.add_argument(
        "export",
        type=Path,
        help="export directory the manifest was validated from",
    )
    split.add_argument(
        "--manifest",
        type=Path,
        required=True,
        help="normalized JSON manifest written by the validate command",
    )
    location = split.add_mutually_exclusive_group()
    location.add_argument(
        "--output",
        type=Path,
        default=None,
        help=(
            "explicit output directory (default: the repository's ignored "
            f"datasets/{DEFAULT_DATASET_NAME} directory)"
        ),
    )
    location.add_argument(
        "--name",
        default=None,
        help=(
            "dataset name under the repository's ignored datasets/ directory "
            f"(default: {DEFAULT_DATASET_NAME})"
        ),
    )
    split.add_argument(
        "--seed",
        type=int,
        default=DEFAULT_SEED,
        help=f"seed for the panorama allocation (default: {DEFAULT_SEED})",
    )
    split.add_argument(
        "--ratios",
        type=float,
        nargs=3,
        metavar=("TRAIN", "VAL", "TEST"),
        default=DEFAULT_RATIOS,
        help="target train, val, and test ratios (default: 0.7 0.2 0.1)",
    )
    split.add_argument(
        "--force",
        action="store_true",
        help="replace a non-empty output directory",
    )

    return parser


if __name__ == "__main__":
    raise SystemExit(main())
