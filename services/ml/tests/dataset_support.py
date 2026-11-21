"""A materialized dataset built from a valid crop export, for training tests.

Kept out of conftest so both orchestration tests and the real-training smoke test can
import it without depending on pytest's conftest import semantics.
"""

import csv
from collections.abc import Mapping, Sequence
from pathlib import Path

from PIL import Image

from dataset import SplitManifest, prepare_export, split_dataset

DATASET_GROUPS = {
    "stupa-induk": ("stupa-01", "stupa-02"),
    "pelataran-timur": ("pelataran-01", "pelataran-02"),
    "relief-panel": ("relief-01", "relief-02"),
}
"""More than one crop per source panorama, so whole groups can fill every split."""


def build_crop_export(
    root: Path, groups: Mapping[str, Sequence[str]] | None = None
) -> Path:
    """A valid YOLO-style crop export with provenance and unique crop pixels."""
    groups = DATASET_GROUPS if groups is None else groups

    (root / "images").mkdir(parents=True)
    (root / "labels").mkdir()
    (root / "panoramas").mkdir()
    (root / "classes.txt").write_text("stupa\nrelief\n", encoding="utf-8")

    rows: list[tuple[str, str]] = []
    colour = 0

    for panorama, crops in groups.items():
        (root / "panoramas" / f"{panorama}.jpg").write_bytes(b"not read by the pipeline")

        for crop in crops:
            Image.new(
                "RGB",
                (16, 16),
                (colour * 37 % 256, colour * 61 % 256, colour * 97 % 256),
            ).save(root / "images" / f"{crop}.png")
            (root / "labels" / f"{crop}.txt").write_text(
                "0 0.5 0.5 0.2 0.2\n", encoding="utf-8"
            )
            rows.append((crop, panorama))
            colour += 1

    with (root / "provenance.csv").open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(["crop", "source_panorama"])
        writer.writerows(rows)

    return root


def materialize_dataset(
    root: Path,
    groups: Mapping[str, Sequence[str]] | None = None,
    *,
    seed: int = 42,
) -> tuple[Path, SplitManifest]:
    """An export split by source panorama into the layout training consumes."""
    export = build_crop_export(root / "export", groups)
    dataset = root / "dataset"
    manifest = split_dataset(prepare_export(export), export, dataset, seed=seed)

    return dataset, manifest
