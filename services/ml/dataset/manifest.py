"""The normalized dataset manifest later stages consume."""

import json
import os
import tempfile
from dataclasses import dataclass
from pathlib import Path

SCHEMA_VERSION = 1
"""Version of the manifest document written by `dataset.write_manifest`."""


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
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(manifest.to_dict(), indent=2) + "\n"

    descriptor, temporary = tempfile.mkstemp(
        prefix=f".{path.name}.",
        suffix=".tmp",
        dir=path.parent,
    )

    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(payload)
        Path(temporary).replace(path)
    finally:
        Path(temporary).unlink(missing_ok=True)
