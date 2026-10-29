"""Command line entry point for validating an external crop export."""

import argparse
import sys
from collections.abc import Sequence
from pathlib import Path

from dataset import DatasetValidationError, prepare_export, write_manifest


def main(argv: Sequence[str] | None = None) -> int:
    parser = _parser()
    arguments = parser.parse_args(argv)

    try:
        manifest = prepare_export(arguments.export)
    except DatasetValidationError as error:
        for issue in error.issues:
            print(issue, file=sys.stderr)
        print(
            f"{len(error.issues)} validation issue(s) found.",
            file=sys.stderr,
        )
        if arguments.manifest.exists():
            print(
                f"Existing manifest '{arguments.manifest}' was left untouched.",
                file=sys.stderr,
            )
        else:
            print("No manifest was written.", file=sys.stderr)
        return 1

    write_manifest(manifest, arguments.manifest)

    boxes = sum(len(crop.boxes) for crop in manifest.crops)
    panorama_count = len(manifest.panoramas)
    panorama_label = "panorama" if panorama_count == 1 else "panoramas"
    print(
        f"Validated {len(manifest.crops)} crops ({boxes} boxes) across "
        f"{panorama_count} source {panorama_label}; wrote {arguments.manifest}."
    )

    return 0


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python -m dataset",
        description=(
            "Validate an external YOLO-style crop export and write its normalized "
            "dataset manifest."
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

    return parser


if __name__ == "__main__":
    raise SystemExit(main())
