"""Reading and verifying a materialized dataset before a training run touches it.

Training consumes only what the split stage wrote: the Ultralytics `data.yaml` and the
split manifest. The manifest is checked for internal consistency and no source panorama
may appear in more than one split, so a leaked dataset is refused before any training
starts. Nothing here imports the runtime service, FastAPI, or a database.
"""

import json
import math
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path

from dataset.split import (
    SPLIT_SCHEMA_VERSION,
    SPLITS,
    SplitManifest,
    SplitSummary,
    dataset_content_digest,
    split_digest,
)
from training.errors import TrainingError

DATA_FILE = "data.yaml"
SPLIT_MANIFEST_FILE = "split-manifest.json"
IMAGES_DIRECTORY = "images"
LABELS_DIRECTORY = "labels"


@dataclass(frozen=True)
class VerifiedDataset:
    """A materialized dataset proven fit to train on, with the digests attributing it."""

    root: Path
    manifest: SplitManifest
    data_yaml: Path

    @property
    def class_catalog(self) -> tuple[str, ...]:
        return self.manifest.class_catalog

    @property
    def seed(self) -> int:
        return self.manifest.seed

    @property
    def ratios(self) -> dict[str, float]:
        return dict(self.manifest.ratios)

    @property
    def dataset_digest(self) -> str:
        return self.manifest.dataset_digest

    @property
    def content_digest(self) -> str:
        return self.manifest.content_digest

    @property
    def split_digest(self) -> str:
        return self.manifest.digest


def verify_dataset(root: Path) -> VerifiedDataset:
    """Reads a materialized dataset and refuses one that cannot be trusted for training."""
    root = Path(root)
    if not root.is_dir():
        raise TrainingError(f"dataset '{root}' is not a directory")

    manifest = read_split_manifest(root / SPLIT_MANIFEST_FILE)
    _reject_leakage(manifest)
    data_yaml, files = _verify_materialized(root, manifest)
    _verify_content(manifest, files)
    _verify_digest(manifest)

    return VerifiedDataset(root=root, manifest=manifest, data_yaml=data_yaml)


def read_split_manifest(path: Path) -> SplitManifest:
    """Reads a split manifest written by `dataset.split_dataset`."""
    path = Path(path)
    payload = _read_object(path)

    schema_version = payload.get("schema_version")
    if (
        not isinstance(schema_version, int)
        or isinstance(schema_version, bool)
        or schema_version != SPLIT_SCHEMA_VERSION
    ):
        raise TrainingError(
            f"split manifest '{path}' has schema_version {schema_version!r}; this tool "
            f"reads version {SPLIT_SCHEMA_VERSION}"
        )

    catalog = _read_catalog(payload.get("class_catalog"), path)
    seed = _read_integer(f"split manifest '{path}' 'seed'", payload.get("seed"))
    ratios = _read_ratios(payload.get("ratios"), path)
    splits = _read_splits(payload.get("splits"), catalog, path)
    counts = _read_counts(
        payload.get("counts"),
        f"split manifest '{path}'",
        images=sum(len(summary.images) for summary in splits.values()),
        panoramas=sum(len(summary.panoramas) for summary in splits.values()),
        boxes=sum(summary.counts["boxes"] for summary in splits.values()),
    )
    distribution = _read_distribution(
        payload.get("class_distribution"), catalog, f"split manifest '{path}'"
    )
    _verify_attribution(distribution, splits, catalog, path)

    return SplitManifest(
        schema_version=SPLIT_SCHEMA_VERSION,
        dataset_digest=_read_digest(
            "dataset_digest", payload.get("dataset_digest"), path
        ),
        content_digest=_read_digest(
            "content_digest", payload.get("content_digest"), path
        ),
        class_catalog=catalog,
        seed=seed,
        ratios=ratios,
        splits=splits,
        counts=counts,
        class_distribution=distribution,
        digest=_read_digest("digest", payload.get("digest"), path),
    )


def _read_object(path: Path) -> dict[str, object]:
    try:
        raw = path.read_bytes()
    except OSError as error:
        raise TrainingError(f"cannot read split manifest '{path}': {error}") from error

    try:
        payload = json.loads(raw.decode("utf-8-sig"))
    except UnicodeDecodeError as error:
        raise TrainingError(
            f"split manifest '{path}' is not valid UTF-8: {error}"
        ) from error
    except json.JSONDecodeError as error:
        raise TrainingError(
            f"split manifest '{path}' is not valid JSON: {error}"
        ) from error

    if not isinstance(payload, dict):
        raise TrainingError(f"split manifest '{path}' must contain a JSON object")

    return payload


def _read_catalog(raw: object, path: Path) -> tuple[str, ...]:
    if (
        not isinstance(raw, list)
        or not raw
        or not all(isinstance(key, str) and key for key in raw)
    ):
        raise TrainingError(f"split manifest '{path}' has no valid 'class_catalog'")

    if len(set(raw)) != len(raw):
        raise TrainingError(f"split manifest '{path}' repeats a class key")

    return tuple(raw)


def _read_ratios(raw: object, path: Path) -> dict[str, float]:
    subject = f"split manifest '{path}' 'ratios'"
    if not isinstance(raw, dict) or set(raw) != set(SPLITS):
        raise TrainingError(
            f"{subject} must name exactly {', '.join(SPLITS)}"
        )

    ratios: dict[str, float] = {}
    for split in SPLITS:
        value = raw[split]
        if (
            not isinstance(value, (int, float))
            or isinstance(value, bool)
            or not math.isfinite(value)
            or value <= 0.0
        ):
            raise TrainingError(f"{subject} has an invalid {split} ratio: {value!r}")
        ratios[split] = float(value)

    if not math.isclose(sum(ratios.values()), 1.0, rel_tol=0.0, abs_tol=1e-9):
        raise TrainingError(
            f"{subject} does not add up to 1.0: {sum(ratios.values())}"
        )

    return ratios


def _read_splits(
    raw: object, catalog: tuple[str, ...], path: Path
) -> dict[str, SplitSummary]:
    if not isinstance(raw, dict) or set(raw) != set(SPLITS):
        raise TrainingError(
            f"split manifest '{path}' 'splits' must name exactly {', '.join(SPLITS)}"
        )

    splits: dict[str, SplitSummary] = {}
    for split in SPLITS:
        entry = raw[split]
        subject = f"split manifest '{path}' split '{split}'"
        if not isinstance(entry, dict):
            raise TrainingError(f"{subject} is not an object")

        panoramas = _read_names(entry.get("panoramas"), f"{subject} 'panoramas'")
        images = _read_names(entry.get("images"), f"{subject} 'images'")
        if not images:
            raise TrainingError(f"{subject} lists no crops")

        splits[split] = SplitSummary(
            panoramas=panoramas,
            images=images,
            counts=_read_counts(
                entry.get("counts"),
                subject,
                images=len(images),
                panoramas=len(panoramas),
                boxes=None,
            ),
            class_distribution=_read_distribution(
                entry.get("class_distribution"), catalog, subject
            ),
        )

    return splits


def _read_names(raw: object, subject: str) -> tuple[str, ...]:
    if (
        not isinstance(raw, list)
        or not all(isinstance(name, str) and name for name in raw)
        or len(set(raw)) != len(raw)
    ):
        raise TrainingError(f"{subject} must be a list of unique names")

    return tuple(raw)


def _read_counts(
    raw: object,
    subject: str,
    *,
    images: int,
    panoramas: int,
    boxes: int | None,
) -> dict[str, int]:
    if not isinstance(raw, dict):
        raise TrainingError(f"{subject} 'counts' is not an object")

    expected = {"images", "labels", "boxes", "panoramas"}
    if set(raw) != expected:
        raise TrainingError(
            f"{subject} 'counts' must contain exactly {', '.join(sorted(expected))}"
        )

    counts = {
        key: _read_integer(f"{subject} '{key}'", raw[key]) for key in sorted(expected)
    }

    for key, expected_value in (
        ("images", images),
        ("labels", images),
        ("panoramas", panoramas),
        ("boxes", boxes),
    ):
        if expected_value is None:
            if counts[key] < 0:
                raise TrainingError(f"{subject} '{key}' cannot be negative")
        elif counts[key] != expected_value:
            raise TrainingError(
                f"{subject} '{key}' is {counts[key]}, but its entries give "
                f"{expected_value}"
            )

    return counts


def _read_distribution(
    raw: object, catalog: tuple[str, ...], subject: str
) -> dict[str, int]:
    if not isinstance(raw, dict):
        raise TrainingError(f"{subject} 'class_distribution' is not an object")
    if set(raw) != set(catalog):
        raise TrainingError(
            f"{subject} 'class_distribution' does not match the class catalog"
        )

    distribution: dict[str, int] = {}
    for key in catalog:
        value = _read_integer(f"{subject} class '{key}'", raw[key])
        if value < 0:
            raise TrainingError(f"{subject} class '{key}' cannot be negative")
        distribution[key] = value

    return distribution


def _read_digest(key: str, raw: object, path: Path) -> str:
    if not isinstance(raw, str) or not raw.startswith("sha256:"):
        raise TrainingError(
            f"split manifest '{path}' is missing its 'sha256:' {key}"
        )

    return raw


def _read_integer(subject: str, raw: object) -> int:
    if not isinstance(raw, int) or isinstance(raw, bool):
        raise TrainingError(f"{subject} must be an integer")

    return raw


def _verify_attribution(
    distribution: dict[str, int],
    splits: dict[str, SplitSummary],
    catalog: tuple[str, ...],
    path: Path,
) -> None:
    summed = {
        key: sum(summary.class_distribution[key] for summary in splits.values())
        for key in catalog
    }
    if summed != distribution:
        raise TrainingError(
            f"split manifest '{path}' records a class distribution that does not match "
            "the sum of its splits"
        )


def _reject_leakage(manifest: SplitManifest) -> None:
    panorama_owner: dict[str, str] = {}
    image_owner: dict[str, str] = {}

    for split in SPLITS:
        summary = manifest.splits[split]

        for panorama in summary.panoramas:
            if panorama in panorama_owner and panorama_owner[panorama] != split:
                raise TrainingError(
                    f"source panorama '{panorama}' spans splits "
                    f"'{panorama_owner[panorama]}' and '{split}'; refusing to train on "
                    "a leaked dataset"
                )
            panorama_owner[panorama] = split

        for image in summary.images:
            if image in image_owner and image_owner[image] != split:
                raise TrainingError(
                    f"crop '{image}' spans splits '{image_owner[image]}' and '{split}'; "
                    "refusing to train on a leaked dataset"
                )
            image_owner[image] = split


def _verify_materialized(
    root: Path, manifest: SplitManifest
) -> tuple[Path, dict[str, dict[str, tuple[Path, Path]]]]:
    data_yaml = root / DATA_FILE
    if not data_yaml.is_file():
        raise TrainingError(f"dataset '{root}' has no '{DATA_FILE}'")
    if data_yaml.stat().st_size == 0:
        raise TrainingError(f"dataset '{root}' has an empty '{DATA_FILE}'")

    _verify_data_yaml(root, data_yaml, manifest)

    files: dict[str, dict[str, tuple[Path, Path]]] = {}

    for split in SPLITS:
        expected = set(manifest.splits[split].images)

        images = _crop_files(
            root / IMAGES_DIRECTORY / split,
            suffix=None,
            subject=f"dataset '{root}' split '{split}' images",
        )
        labels = _crop_files(
            root / LABELS_DIRECTORY / split,
            suffix=".txt",
            subject=f"dataset '{root}' split '{split}' labels",
        )

        _compare_files(split, "image", expected, images)
        _compare_files(split, "label", expected, labels)

        files[split] = {
            name: (images[name], labels[name])
            for name in manifest.splits[split].images
        }

    return data_yaml, files


def _verify_data_yaml(root: Path, data_yaml: Path, manifest: SplitManifest) -> None:
    """The generated `data.yaml` must still describe this dataset and its class catalog."""
    try:
        text = data_yaml.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as error:
        raise TrainingError(f"cannot read '{data_yaml}': {error}") from error

    lines = [
        line
        for line in text.splitlines()
        if line.strip() and not line.lstrip().startswith("#")
    ]

    declared = next((line for line in lines if line.startswith("path:")), None)
    if declared is None:
        raise TrainingError(f"'{data_yaml}' does not declare the dataset 'path:'")

    declared_path = Path(_yaml_scalar(declared.partition(":")[2], data_yaml))
    if declared_path.resolve() != root.resolve():
        raise TrainingError(
            f"'{data_yaml}' points at '{declared_path.resolve()}' rather than this "
            f"dataset '{root.resolve()}'; re-run `python -m dataset split` for the "
            "new location"
        )

    catalog = _yaml_catalog(lines, data_yaml)
    if catalog != manifest.class_catalog:
        raise TrainingError(
            f"'{data_yaml}' class names {catalog} do not match the split manifest's "
            f"class catalog {manifest.class_catalog}"
        )


def _yaml_scalar(raw: str, data_yaml: Path) -> str:
    value = raw.strip()
    if not value:
        raise TrainingError(f"'{data_yaml}' has an empty value")

    if value.startswith('"'):
        try:
            scalar = json.loads(value)
        except json.JSONDecodeError as error:
            raise TrainingError(
                f"'{data_yaml}' has an unreadable quoted value {value!r}: {error}"
            ) from error
        if not isinstance(scalar, str):
            raise TrainingError(f"'{data_yaml}' has a non-string value {value!r}")

        return scalar

    return value.strip("'")


def _yaml_catalog(lines: Sequence[str], data_yaml: Path) -> tuple[str, ...]:
    start = next(
        (index for index, line in enumerate(lines) if line.strip() == "names:"),
        None,
    )
    if start is None:
        raise TrainingError(f"'{data_yaml}' does not declare 'names:'")

    entries: dict[int, str] = {}
    for line in lines[start + 1 :]:
        if not line.startswith(" "):
            break

        key, separator, value = line.partition(":")
        if not separator:
            raise TrainingError(f"'{data_yaml}' has an unreadable class name '{line}'")
        try:
            index = int(key.strip())
        except ValueError as error:
            raise TrainingError(
                f"'{data_yaml}' has a class name without an integer index: '{line}'"
            ) from error

        entries[index] = _yaml_scalar(value, data_yaml)

    if not entries or sorted(entries) != list(range(len(entries))):
        raise TrainingError(
            f"'{data_yaml}' class names must be indexed from zero without gaps"
        )

    return tuple(entries[index] for index in sorted(entries))


def _crop_files(directory: Path, *, suffix: str | None, subject: str) -> dict[str, Path]:
    if not directory.is_dir():
        raise TrainingError(f"{subject} directory is missing: {directory}")

    files: dict[str, Path] = {}
    for path in sorted(directory.iterdir()):
        if not path.is_file() or path.name.startswith("."):
            continue
        if suffix is not None and path.suffix != suffix:
            continue

        if path.stem in files:
            raise TrainingError(
                f"{subject} contains more than one file for crop '{path.stem}'"
            )
        files[path.stem] = path

    return files


def _compare_files(
    split: str, kind: str, expected: set[str], files: dict[str, Path]
) -> None:
    missing = sorted(expected - set(files))
    if missing:
        raise TrainingError(
            f"split '{split}' is missing {kind}(s) the manifest lists: "
            + ", ".join(missing)
        )

    extra = sorted(set(files) - expected)
    if extra:
        raise TrainingError(
            f"split '{split}' contains {kind}(s) the manifest does not list: "
            + ", ".join(extra)
        )


def _verify_content(
    manifest: SplitManifest, files: dict[str, dict[str, tuple[Path, Path]]]
) -> None:
    computed = dataset_content_digest(files)
    if computed != manifest.content_digest:
        raise TrainingError(
            f"dataset content digest {computed} does not match the split manifest's "
            f"{manifest.content_digest}; refusing to train on a dataset whose images "
            "or labels changed after it was split"
        )


def _verify_digest(manifest: SplitManifest) -> None:
    computed = split_digest(
        manifest.dataset_digest,
        manifest.seed,
        manifest.ratios,
        manifest.splits,
        manifest.content_digest,
    )
    if computed != manifest.digest:
        raise TrainingError(
            f"split manifest digest {manifest.digest} does not match its recorded "
            f"dataset, content, seed, ratios, and split membership ({computed}); "
            "refusing to train on a dataset that changed after it was split"
        )
