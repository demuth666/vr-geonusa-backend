"""Deterministic, leakage-free splitting of a validated crop export.

Whole panoramas are allocated to train, validation, or test, so no crop from one source
panorama can be found on both sides of the evaluation boundary. Split membership is a
pure function of the manifest, the seed, and the target ratios.
"""

import hashlib
import json
import math
import shutil
import tempfile
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from pathlib import Path

from dataset.hashing import feed, file_digest
from dataset.manifest import Crop, DatasetManifest, write_json
from dataset.validation import prepare_export
from repository import repository_root

SPLIT_SCHEMA_VERSION = 2
"""Version of the split manifest document written by `dataset.split_dataset`."""

SPLITS = ("train", "val", "test")
DEFAULT_SEED = 42
DEFAULT_RATIOS = (0.7, 0.2, 0.1)
CANDIDATE_ALLOCATIONS = 32
"""Seeded candidate orders considered before choosing the closest allocation."""

DATA_FILE = "data.yaml"
SPLIT_MANIFEST_FILE = "split-manifest.json"
DEFAULT_DATASET_NAME = "borobudur"


class SplitError(ValueError):
    """Raised when an export cannot be split into leakage-free train, val, and test sets."""


@dataclass(frozen=True)
class SplitSummary:
    """One materialized split and the counts a training run needs to attribute it."""

    panoramas: tuple[str, ...]
    images: tuple[str, ...]
    counts: dict[str, int]
    class_distribution: dict[str, int]

    def to_dict(self) -> dict[str, object]:
        return {
            "panoramas": list(self.panoramas),
            "images": list(self.images),
            "counts": dict(self.counts),
            "class_distribution": dict(self.class_distribution),
        }


@dataclass(frozen=True)
class SplitManifest:
    """The record of how one validated export became a train/val/test dataset."""

    schema_version: int
    dataset_digest: str
    """Digest of the export inputs, as recorded by the dataset manifest."""
    content_digest: str
    """Digest of the copied image and label bytes, split by split."""
    class_catalog: tuple[str, ...]
    seed: int
    ratios: dict[str, float]
    splits: dict[str, SplitSummary]
    counts: dict[str, int]
    class_distribution: dict[str, int]
    digest: str

    def to_dict(self) -> dict[str, object]:
        return {
            "schema_version": self.schema_version,
            "dataset_digest": self.dataset_digest,
            "content_digest": self.content_digest,
            "class_catalog": list(self.class_catalog),
            "seed": self.seed,
            "ratios": dict(self.ratios),
            "splits": {
                split: summary.to_dict() for split, summary in self.splits.items()
            },
            "counts": dict(self.counts),
            "class_distribution": dict(self.class_distribution),
            "digest": self.digest,
        }


def default_output(name: str = DEFAULT_DATASET_NAME) -> Path:
    """The named, ignored dataset directory at the repository root."""
    candidate = Path(name)
    if (
        not name
        or candidate.is_absolute()
        or len(candidate.parts) != 1
        or name in {".", ".."}
    ):
        raise SplitError(
            f"dataset name must be a single directory name, got {name!r}"
        )

    return repository_root() / "datasets" / name


def split_dataset(
    manifest: DatasetManifest,
    export: Path,
    output: Path,
    *,
    seed: int = DEFAULT_SEED,
    ratios: Sequence[float] = DEFAULT_RATIOS,
    force: bool = False,
) -> SplitManifest:
    """Allocates whole panorama groups and materializes them under `output`.

    The export is validated again and must still match the manifest's digest, so a
    dataset is never materialized from inputs that changed after validation. Raises
    `SplitError` before writing anything when the export cannot fill all three splits
    without leaking a panorama, or when `output` would replace the export.
    """
    export = Path(export)
    output = Path(output)
    resolved_ratios = _validate_ratios(ratios)

    current = prepare_export(export)
    if current.digest != manifest.digest:
        raise SplitError(
            f"export '{export}' no longer matches the recorded dataset manifest; "
            "re-run `python -m dataset validate` before splitting"
        )
    manifest = current

    assignment = _allocate(manifest, seed, resolved_ratios)
    _assert_no_leakage(manifest, assignment)
    _check_output(output, export, force=force)

    crops_by_name = {crop.name: crop for crop in manifest.crops}
    splits = {
        split: _summary(manifest, assignment[split], crops_by_name) for split in SPLITS
    }
    counts = {
        "images": sum(summary.counts["images"] for summary in splits.values()),
        "labels": sum(summary.counts["labels"] for summary in splits.values()),
        "boxes": sum(summary.counts["boxes"] for summary in splits.values()),
        "panoramas": sum(summary.counts["panoramas"] for summary in splits.values()),
    }
    distribution = {key: 0 for key in manifest.class_catalog}
    for summary in splits.values():
        for key, value in summary.class_distribution.items():
            distribution[key] += value

    content_digest = dataset_content_digest(
        {
            split: {
                name: (
                    export / crops_by_name[name].image,
                    export / crops_by_name[name].label,
                )
                for name in splits[split].images
            }
            for split in SPLITS
        }
    )

    split_manifest = SplitManifest(
        schema_version=SPLIT_SCHEMA_VERSION,
        dataset_digest=manifest.digest,
        content_digest=content_digest,
        class_catalog=manifest.class_catalog,
        seed=seed,
        ratios=resolved_ratios,
        splits=splits,
        counts=counts,
        class_distribution=distribution,
        digest=split_digest(
            manifest.digest, seed, resolved_ratios, splits, content_digest
        ),
    )

    _materialize(manifest, export, output, assignment, split_manifest, crops_by_name)

    return split_manifest


def _validate_ratios(values: Sequence[float]) -> dict[str, float]:
    if len(values) != len(SPLITS):
        raise SplitError(
            f"expected {len(SPLITS)} ratios (train, val, test), got {len(values)}"
        )

    ratios: dict[str, float] = {}
    for split, value in zip(SPLITS, values, strict=True):
        value = float(value)
        if not math.isfinite(value) or value <= 0.0:
            raise SplitError(
                f"the {split} ratio must be a finite number greater than zero, got {value}"
            )
        ratios[split] = value

    total = sum(ratios.values())
    if not math.isclose(total, 1.0, rel_tol=0.0, abs_tol=1e-9):
        raise SplitError(f"the ratios must add up to 1.0, got {total}")

    return ratios


def _allocate(
    manifest: DatasetManifest, seed: int, ratios: dict[str, float]
) -> dict[str, tuple[str, ...]]:
    groups = sorted(manifest.panoramas.items())
    if len(groups) < len(SPLITS):
        raise SplitError(
            f"the export has {len(groups)} source panorama(s); at least "
            f"{len(SPLITS)} are required to fill train, val, and test without "
            "putting one panorama in more than one split"
        )

    empty = [panorama for panorama, crops in groups if not crops]
    if empty:
        raise SplitError(
            "source panorama(s) "
            + ", ".join(repr(panorama) for panorama in empty)
            + " declare no crops; add crops or remove the panorama from the export"
        )

    targets = {split: ratios[split] * len(manifest.crops) for split in SPLITS}
    sizes = {panorama: len(crops) for panorama, crops in groups}

    best_key: tuple[float, str, int] | None = None
    best: dict[str, list[str]] | None = None

    for restart, order in enumerate(_candidate_orders(groups, seed)):
        assigned = _greedy(order, targets)
        _fill_empty_splits(assigned, groups)

        counts = {
            split: sum(sizes[panorama] for panorama in assigned[split])
            for split in SPLITS
        }
        cost = sum(abs(counts[split] - targets[split]) for split in SPLITS)
        key = (round(cost, 9), _rank(seed, "pick", restart), restart)

        if best_key is None or key < best_key:
            best_key = key
            best = assigned

    if best is None:
        raise SplitError("the source panoramas cannot fill train, val, and test")

    return {
        split: tuple(sorted(panoramas)) for split, panoramas in best.items()
    }


def _candidate_orders(
    groups: Sequence[tuple[str, tuple[str, ...]]], seed: int
) -> Sequence[Sequence[tuple[str, tuple[str, ...]]]]:
    """Seeded orders from which the closest whole-group allocation is chosen.

    The first order places the largest panoramas first, which keeps a small early group
    from consuming capacity a large group needs. The rest are fully seeded orders that
    let a different seed explore other allocations without ever splitting a panorama.
    """
    orders: list[Sequence[tuple[str, tuple[str, ...]]]] = [
        sorted(
            groups,
            key=lambda group: (
                -len(group[1]),
                _rank(seed, 0, group[0]),
                group[0],
            ),
        )
    ]

    for restart in range(1, CANDIDATE_ALLOCATIONS):
        orders.append(
            sorted(
                groups,
                key=lambda group: (_rank(seed, restart, group[0]), group[0]),
            )
        )

    return orders


def _rank(seed: int, restart: int | str, value: str | int) -> str:
    """A stable, seeded ordering key that does not depend on the interpreter's PRNG."""
    return hashlib.sha256(f"{seed}\0{restart}\0{value}".encode("utf-8")).hexdigest()


def _greedy(
    order: Sequence[tuple[str, tuple[str, ...]]], targets: dict[str, float]
) -> dict[str, list[str]]:
    assigned: dict[str, list[str]] = {split: [] for split in SPLITS}
    counts = {split: 0 for split in SPLITS}

    for panorama, crops in order:
        split = max(SPLITS, key=lambda name: targets[name] - counts[name])
        assigned[split].append(panorama)
        counts[split] += len(crops)

    return assigned


def _fill_empty_splits(
    assigned: dict[str, list[str]], groups: Sequence[tuple[str, tuple[str, ...]]]
) -> None:
    """Moves the smallest whole group so train, val, and test all exist.

    A split is only filled from a donor that keeps at least one group, and the smallest
    available group is moved so an empty split never steals a large panorama from a
    useful one.
    """
    sizes = {panorama: len(crops) for panorama, crops in groups}

    for split in SPLITS:
        if assigned[split]:
            continue

        movable = [
            (sizes[panorama], panorama, donor)
            for donor in SPLITS
            if len(assigned[donor]) > 1
            for panorama in assigned[donor]
        ]
        if not movable:
            raise SplitError(
                "the source panoramas cannot fill train, val, and test without "
                "putting a panorama in more than one split"
            )

        _, moved, donor = min(movable)
        assigned[donor].remove(moved)
        assigned[split].append(moved)


def _assert_no_leakage(
    manifest: DatasetManifest, assignment: dict[str, tuple[str, ...]]
) -> None:
    panorama_owner: dict[str, str] = {}

    for split, panoramas in assignment.items():
        for panorama in panoramas:
            if panorama in panorama_owner and panorama_owner[panorama] != split:
                raise SplitError(
                    f"source panorama '{panorama}' would span splits "
                    f"'{panorama_owner[panorama]}' and '{split}'"
                )
            panorama_owner[panorama] = split

    crop_owner: dict[str, str] = {}
    for split, panoramas in assignment.items():
        for panorama in panoramas:
            if panorama not in manifest.panoramas:
                raise SplitError(
                    f"split '{split}' names unknown source panorama '{panorama}'"
                )

            for crop in manifest.panoramas[panorama]:
                if crop in crop_owner and crop_owner[crop] != split:
                    raise SplitError(
                        f"crop '{crop}' from panorama '{panorama}' would appear in "
                        f"both '{crop_owner[crop]}' and '{split}'"
                    )
                crop_owner[crop] = split

    expected = {crop.name for crop in manifest.crops}
    if set(crop_owner) != expected:
        missing = ", ".join(sorted(expected - set(crop_owner)))
        raise SplitError(f"split would omit crop(s): {missing}")


def _summary(
    manifest: DatasetManifest,
    panoramas: tuple[str, ...],
    crops_by_name: dict[str, Crop],
) -> SplitSummary:
    names = sorted(
        name for panorama in panoramas for name in manifest.panoramas[panorama]
    )
    crops = [crops_by_name[name] for name in names]

    distribution = {key: 0 for key in manifest.class_catalog}
    for crop in crops:
        for box in crop.boxes:
            distribution[box.class_key] += 1

    return SplitSummary(
        panoramas=panoramas,
        images=tuple(names),
        counts={
            "images": len(crops),
            "labels": len(crops),
            "boxes": sum(len(crop.boxes) for crop in crops),
            "panoramas": len(panoramas),
        },
        class_distribution=distribution,
    )


def split_digest(
    dataset_digest: str,
    seed: int,
    ratios: Mapping[str, float],
    splits: Mapping[str, SplitSummary],
    content_digest: str,
) -> str:
    """The digest a split manifest records for its inputs and its result.

    It covers only what the split manifest itself records, so a later stage such as
    training can recompute it without the export the split was materialized from.
    """
    digest = hashlib.sha256()
    feed(digest, "schema_version", str(SPLIT_SCHEMA_VERSION))
    feed(digest, "dataset_digest", dataset_digest)
    feed(digest, "content_digest", content_digest)
    feed(digest, "seed", str(seed))

    for split in SPLITS:
        feed(digest, "ratio", f"{split}={ratios[split]!r}")
        for panorama in splits[split].panoramas:
            feed(digest, "panorama", panorama)
        for image in splits[split].images:
            feed(digest, "crop", image)

    return f"sha256:{digest.hexdigest()}"


def dataset_content_digest(
    files: Mapping[str, Mapping[str, tuple[Path, Path]]],
) -> str:
    """Digest of the materialized dataset: every crop's image and label bytes.

    `files` maps each split to its crops and each crop to its image and label path, so
    the split stage and the training stage hash exactly the same pieces.
    """
    digest = hashlib.sha256()
    feed(digest, "schema_version", str(SPLIT_SCHEMA_VERSION))

    for split in SPLITS:
        for name in sorted(files[split]):
            image, label = files[split][name]
            feed(digest, "split", split)
            feed(digest, "crop", name)
            feed(digest, "image", _file_digest(image))
            feed(digest, "label", _file_digest(label))

    return f"sha256:{digest.hexdigest()}"


def _file_digest(path: Path) -> str:
    try:
        return file_digest(path)
    except OSError as error:
        raise SplitError(f"crop file '{path}' cannot be read: {error}") from error


def _check_output(output: Path, export: Path, *, force: bool) -> None:
    resolved_output = output.resolve()
    resolved_export = export.resolve()

    if resolved_output == resolved_export or resolved_export.is_relative_to(
        resolved_output
    ):
        raise SplitError(
            f"output '{output}' would replace the export '{export}'; "
            "choose a directory outside the export"
        )

    if resolved_output.is_relative_to(resolved_export):
        raise SplitError(
            f"output '{output}' is inside the export '{export}'; "
            "choose a directory outside the export"
        )

    cwd = Path.cwd().resolve()
    if cwd.is_relative_to(resolved_output) or (resolved_output / ".git").exists():
        raise SplitError(
            f"output '{output}' contains the working directory or a checkout; "
            "choose a dedicated dataset directory"
        )

    if output.is_symlink():
        raise SplitError(
            f"output '{output}' is a symbolic link; choose a real directory"
        )

    if output.exists() and not output.is_dir():
        raise SplitError(f"output '{output}' exists and is not a directory")

    if output.is_dir() and any(output.iterdir()) and not force:
        raise SplitError(
            f"output '{output}' is not empty; pass --force to replace it"
        )


def _materialize(
    manifest: DatasetManifest,
    export: Path,
    output: Path,
    assignment: dict[str, tuple[str, ...]],
    split_manifest: SplitManifest,
    crops_by_name: dict[str, Crop],
) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    staging = Path(tempfile.mkdtemp(prefix=f".{output.name}.", dir=output.parent))

    try:
        for split in SPLITS:
            _write_split(
                staging,
                export,
                split,
                assignment[split],
                manifest,
                crops_by_name,
            )

        (staging / DATA_FILE).write_text(
            _data_yaml(output, manifest.class_catalog),
            encoding="utf-8",
            newline="\n",
        )
        write_json(split_manifest.to_dict(), staging / SPLIT_MANIFEST_FILE)

        _replace_output(staging, output)
    except BaseException:
        shutil.rmtree(staging, ignore_errors=True)
        raise


def _replace_output(staging: Path, output: Path) -> None:
    """Moves the finished dataset into place, restoring the old one if the move fails."""
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


def _write_split(
    staging: Path,
    export: Path,
    split: str,
    panoramas: tuple[str, ...],
    manifest: DatasetManifest,
    crops_by_name: dict[str, Crop],
) -> None:
    images = staging / "images" / split
    labels = staging / "labels" / split
    images.mkdir(parents=True)
    labels.mkdir(parents=True)

    for panorama in panoramas:
        for name in manifest.panoramas[panorama]:
            crop = crops_by_name[name]
            _copy(
                export,
                crop.image,
                images / f"{crop.name}{Path(crop.image).suffix}",
            )
            _copy(export, crop.label, labels / f"{crop.name}.txt")


def _copy(export: Path, relative_name: str, destination: Path) -> None:
    relative = Path(relative_name)
    if relative.is_absolute() or ".." in relative.parts:
        raise SplitError(f"crop path '{relative_name}' escapes the export")

    source = export / relative
    if not source.is_file():
        raise SplitError(f"crop file '{relative_name}' is missing from the export")

    shutil.copyfile(source, destination)


def _data_yaml(output: Path, catalog: Sequence[str]) -> str:
    lines = [
        "# Generated by `python -m dataset split`; do not edit.",
        f"path: {_yaml_string(output.resolve().as_posix())}",
        "train: images/train",
        "val: images/val",
        "test: images/test",
        "names:",
    ]
    lines.extend(
        f"  {index}: {_yaml_string(key)}" for index, key in enumerate(catalog)
    )

    return "\n".join(lines) + "\n"


def _yaml_string(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)
