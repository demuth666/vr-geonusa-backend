"""The normalized dataset manifest later stages consume."""

import json
import math
import os
import tempfile
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path

SCHEMA_VERSION = 1
"""Version of the manifest document written by `dataset.write_manifest`."""


class DatasetManifestError(ValueError):
    """Raised when a written manifest cannot be read or does not make sense."""


@dataclass(frozen=True)
class BoundingBox:
    """One object-level annotation, in normalized YOLO (xywh) coordinates."""

    class_index: int
    class_key: str
    x_center: float
    y_center: float
    width: float
    height: float

    def to_dict(self) -> dict[str, object]:
        return {
            "class_index": self.class_index,
            "class_key": self.class_key,
            "x_center": self.x_center,
            "y_center": self.y_center,
            "width": self.width,
            "height": self.height,
        }


@dataclass(frozen=True)
class Crop:
    """One annotated crop and the panorama it was taken from."""

    name: str
    image: str
    """Image path relative to the export root, using POSIX separators."""
    label: str
    """Label path relative to the export root, using POSIX separators."""
    source_panorama: str
    boxes: tuple[BoundingBox, ...]

    def to_dict(self) -> dict[str, object]:
        return {
            "name": self.name,
            "image": self.image,
            "label": self.label,
            "source_panorama": self.source_panorama,
            "boxes": [box.to_dict() for box in self.boxes],
        }


@dataclass(frozen=True)
class DatasetManifest:
    """A validated export, normalized for splitting and training."""

    schema_version: int
    class_catalog: tuple[str, ...]
    crops: tuple[Crop, ...]
    panoramas: dict[str, tuple[str, ...]]
    class_distribution: dict[str, int]
    digest: str
    """Digest of the crops, labels, class catalog, provenance, and panorama identities."""

    def to_dict(self) -> dict[str, object]:
        return {
            "schema_version": self.schema_version,
            "class_catalog": list(self.class_catalog),
            "counts": {
                "crops": len(self.crops),
                "boxes": sum(len(crop.boxes) for crop in self.crops),
                "panoramas": len(self.panoramas),
            },
            "panoramas": {
                panorama: list(crops) for panorama, crops in self.panoramas.items()
            },
            "crops": [crop.to_dict() for crop in self.crops],
            "class_distribution": self.class_distribution,
            "digest": self.digest,
        }


def write_manifest(manifest: DatasetManifest, path: Path) -> None:
    """Writes the manifest as stable JSON, replacing `path` atomically."""
    write_json(manifest.to_dict(), path)


def write_json(payload: object, path: Path) -> None:
    """Writes stable JSON, replacing `path` atomically."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    document = json.dumps(payload, indent=2) + "\n"

    descriptor, temporary = tempfile.mkstemp(
        prefix=f".{path.name}.",
        suffix=".tmp",
        dir=path.parent,
    )

    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(document)
        Path(temporary).replace(path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def read_manifest(path: Path) -> DatasetManifest:
    """Reads a manifest written by `write_manifest` back into its dataclass form."""
    path = Path(path)
    payload = _read_object(path)

    schema_version = payload.get("schema_version")
    if (
        not isinstance(schema_version, int)
        or isinstance(schema_version, bool)
        or schema_version != SCHEMA_VERSION
    ):
        raise DatasetManifestError(
            f"dataset manifest '{path}' has schema_version {schema_version!r}; "
            f"this tool reads version {SCHEMA_VERSION}"
        )

    catalog = _read_catalog(payload.get("class_catalog"), path)
    crops = _read_crops(payload.get("crops"), catalog, path)
    panoramas = _read_panoramas(payload.get("panoramas"), crops, path)
    class_distribution = _read_class_distribution(
        payload.get("class_distribution"), catalog, path
    )
    digest = payload.get("digest")
    if not isinstance(digest, str) or not digest.startswith("sha256:"):
        raise DatasetManifestError(
            f"dataset manifest '{path}' is missing its 'sha256:' input digest"
        )

    return DatasetManifest(
        schema_version=SCHEMA_VERSION,
        class_catalog=catalog,
        crops=tuple(crops),
        panoramas=panoramas,
        class_distribution=class_distribution,
        digest=digest,
    )


def _read_object(path: Path) -> dict[str, object]:
    try:
        raw = path.read_bytes()
    except OSError as error:
        raise DatasetManifestError(
            f"cannot read dataset manifest '{path}': {error}"
        ) from error

    try:
        payload = json.loads(raw.decode("utf-8-sig"))
    except UnicodeDecodeError as error:
        raise DatasetManifestError(
            f"dataset manifest '{path}' is not valid UTF-8: {error}"
        ) from error
    except json.JSONDecodeError as error:
        raise DatasetManifestError(
            f"dataset manifest '{path}' is not valid JSON: {error}"
        ) from error

    if not isinstance(payload, dict):
        raise DatasetManifestError(
            f"dataset manifest '{path}' must contain a JSON object"
        )

    return payload


def _read_catalog(raw: object, path: Path) -> tuple[str, ...]:
    if (
        not isinstance(raw, list)
        or not raw
        or not all(isinstance(key, str) and key for key in raw)
    ):
        raise DatasetManifestError(
            f"dataset manifest '{path}' has no valid 'class_catalog'"
        )

    if len(set(raw)) != len(raw):
        raise DatasetManifestError(
            f"dataset manifest '{path}' repeats a class key in 'class_catalog'"
        )

    return tuple(raw)


def _read_crops(
    raw: object, catalog: tuple[str, ...], path: Path
) -> list[Crop]:
    if not isinstance(raw, list):
        raise DatasetManifestError(
            f"dataset manifest '{path}' has no valid 'crops' list"
        )

    crops: list[Crop] = []
    names: set[str] = set()

    for index, entry in enumerate(raw):
        subject = f"dataset manifest '{path}' crop {index}"
        if not isinstance(entry, dict):
            raise DatasetManifestError(f"{subject} is not an object")

        crop = Crop(
            name=_read_string(entry, "name", subject),
            image=_read_string(entry, "image", subject),
            label=_read_string(entry, "label", subject),
            source_panorama=_read_string(entry, "source_panorama", subject),
            boxes=_read_boxes(entry.get("boxes"), catalog, subject),
        )

        if crop.name in names:
            raise DatasetManifestError(f"{subject} duplicates crop '{crop.name}'")
        names.add(crop.name)
        crops.append(crop)

    return crops


def _read_string(entry: dict[str, object], key: str, subject: str) -> str:
    value = entry.get(key)
    if not isinstance(value, str) or not value:
        raise DatasetManifestError(f"{subject} is missing '{key}'")

    return value


def _read_boxes(
    raw: object, catalog: tuple[str, ...], subject: str
) -> tuple[BoundingBox, ...]:
    if not isinstance(raw, list):
        raise DatasetManifestError(f"{subject} has no valid 'boxes' list")

    boxes: list[BoundingBox] = []

    for position, entry in enumerate(raw, 1):
        box_subject = f"{subject} box {position}"
        if not isinstance(entry, dict):
            raise DatasetManifestError(f"{box_subject} is not an object")

        class_index = entry.get("class_index")
        if (
            not isinstance(class_index, int)
            or isinstance(class_index, bool)
            or not 0 <= class_index < len(catalog)
        ):
            raise DatasetManifestError(
                f"{box_subject} has no valid 'class_index' for the class catalog"
            )

        class_key = entry.get("class_key")
        if class_key != catalog[class_index]:
            raise DatasetManifestError(
                f"{box_subject} names class_key {class_key!r}, "
                f"which is not catalog index {class_index}"
            )

        boxes.append(
            BoundingBox(
                class_index=class_index,
                class_key=class_key,
                x_center=_read_coordinate(entry, "x_center", box_subject),
                y_center=_read_coordinate(entry, "y_center", box_subject),
                width=_read_coordinate(entry, "width", box_subject),
                height=_read_coordinate(entry, "height", box_subject),
            )
        )

    return tuple(boxes)


def _read_coordinate(entry: dict[str, object], key: str, subject: str) -> float:
    value = entry.get(key)
    if (
        not isinstance(value, (int, float))
        or isinstance(value, bool)
        or not math.isfinite(value)
    ):
        raise DatasetManifestError(f"{subject} has no finite '{key}'")

    return float(value)


def _read_panoramas(
    raw: object, crops: list[Crop], path: Path
) -> dict[str, tuple[str, ...]]:
    if not isinstance(raw, dict):
        raise DatasetManifestError(
            f"dataset manifest '{path}' has no valid 'panoramas' mapping"
        )

    panoramas: dict[str, tuple[str, ...]] = {}
    for panorama, names in raw.items():
        if (
            not isinstance(panorama, str)
            or not panorama
            or not isinstance(names, list)
            or not all(isinstance(name, str) and name for name in names)
        ):
            raise DatasetManifestError(
                f"dataset manifest '{path}' has an invalid panorama group"
            )
        if len(names) != len(set(names)):
            raise DatasetManifestError(
                f"dataset manifest '{path}' repeats a crop in panorama '{panorama}'"
            )
        panoramas[panorama] = tuple(names)

    expected: dict[str, set[str]] = defaultdict(set)
    for crop in crops:
        expected[crop.source_panorama].add(crop.name)

    if not set(expected).issubset(set(panoramas)):
        missing = ", ".join(sorted(set(expected) - set(panoramas)))
        raise DatasetManifestError(
            f"dataset manifest '{path}' does not list panorama(s) {missing} "
            "named by its crops"
        )

    for panorama, names in panoramas.items():
        if set(names) != expected.get(panorama, set()):
            raise DatasetManifestError(
                f"dataset manifest '{path}' does not match the crops of "
                f"panorama '{panorama}'"
            )

    return panoramas


def _read_class_distribution(
    raw: object, catalog: tuple[str, ...], path: Path
) -> dict[str, int]:
    if not isinstance(raw, dict) or set(raw) != set(catalog):
        raise DatasetManifestError(
            f"dataset manifest '{path}' has a class distribution that does not match "
            "the class catalog"
        )

    distribution: dict[str, int] = {}
    for key in catalog:
        value = raw[key]
        if not isinstance(value, int) or isinstance(value, bool) or value < 0:
            raise DatasetManifestError(
                f"dataset manifest '{path}' has an invalid count for class '{key}'"
            )
        distribution[key] = value

    return distribution
