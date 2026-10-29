"""Proves an external crop export is fit to become a training dataset.

Nothing here imports the runtime service, FastAPI, Ultralytics, or a database. It reads
only the export the operator points at and never an Inference Run image.
"""

import csv
import hashlib
import io
import math
from collections import defaultdict
from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from io import BytesIO
from pathlib import Path
from typing import Protocol

from PIL import Image, UnidentifiedImageError

from dataset.manifest import (
    SCHEMA_VERSION,
    BoundingBox,
    Crop,
    DatasetManifest,
)

IMAGES_DIRECTORY = "images"
LABELS_DIRECTORY = "labels"
PANORAMAS_DIRECTORY = "panoramas"
CLASS_CATALOG = "classes.txt"
PROVENANCE_FILE = "provenance.csv"

IMAGE_SUFFIXES = frozenset({".bmp", ".jpeg", ".jpg", ".png", ".tif", ".tiff", ".webp"})
COORDINATE_TOLERANCE = 1e-9
"""Absorbs float round-off when a box touches the edge of the normalized image."""


@dataclass(frozen=True)
class ValidationIssue:
    """One reason an export cannot become a training dataset."""

    subject: str
    rule: str

    def __str__(self) -> str:
        return f"{self.subject}: {self.rule}"


class DatasetValidationError(ValueError):
    """Raised with every problem found in an export, rather than only the first."""

    def __init__(self, issues: Iterable[ValidationIssue]) -> None:
        self.issues = tuple(issues)
        super().__init__("\n".join(str(issue) for issue in self.issues))


class _Hash(Protocol):
    def update(self, data: bytes) -> None: ...


@dataclass(frozen=True)
class _Image:
    name: str
    path: Path
    relative_path: str


@dataclass(frozen=True)
class _ValidatedCrop:
    crop: Crop
    image_hash: str | None
    label_hash: str | None


def prepare_export(export: Path) -> DatasetManifest:
    """Validates an export and returns its normalized manifest.

    Raises `DatasetValidationError` listing every violation instead of returning a
    partial manifest.
    """
    export = Path(export)
    issues: list[ValidationIssue] = []

    if not export.is_dir():
        raise DatasetValidationError(
            [ValidationIssue(f"export '{export}'", "is not a directory")]
        )

    catalog, catalog_digest = _read_catalog(export / CLASS_CATALOG, issues)
    panorama_ids, panorama_digest = _read_panoramas(export / PANORAMAS_DIRECTORY, issues)
    provenance, provenance_digest = _read_provenance(
        export / PROVENANCE_FILE, panorama_ids, issues
    )
    images = _scan_images(export, issues)

    image_hashes: dict[str, str] = {}
    label_hashes: dict[str, str] = {}
    crops: list[Crop] = []

    images_by_name = {image.name: image for image in images}
    for crop_name in provenance:
        if crop_name not in images_by_name:
            issues.append(
                ValidationIssue(
                    f"provenance.csv entry '{crop_name}'",
                    "names a crop the export does not contain",
                )
            )

    for image in images:
        source_panorama = provenance.get(image.name, "")
        if image.name not in provenance:
            issues.append(
                ValidationIssue(
                    f"crop '{image.name}'",
                    "has no source panorama in provenance.csv",
                )
            )

        label_path = _label_path(export, image)
        label_relative = label_path.relative_to(export).as_posix()
        validated = _validate_crop(
            image,
            label_path,
            label_relative,
            catalog,
            source_panorama,
            issues,
        )
        crops.append(validated.crop)

        if validated.image_hash is not None:
            image_hashes[image.name] = validated.image_hash
        if validated.label_hash is not None:
            label_hashes[image.name] = validated.label_hash

    _reject_duplicate_image_content(image_hashes, issues)

    if crops and not any(crop.boxes for crop in crops):
        issues.append(
            ValidationIssue(
                "export",
                "contains no object-level bounding boxes to train on",
            )
        )

    if issues:
        raise DatasetValidationError(issues)

    crops.sort(key=lambda crop: crop.name)
    panoramas = _group_by_panorama(panorama_ids, crops)
    class_distribution = _class_distribution(catalog, crops)
    digest = _digest(
        catalog_digest=catalog_digest,
        provenance_digest=provenance_digest,
        panorama_digest=panorama_digest,
        crops=crops,
        image_hashes=image_hashes,
        label_hashes=label_hashes,
    )

    return DatasetManifest(
        schema_version=SCHEMA_VERSION,
        class_catalog=tuple(catalog),
        crops=tuple(crops),
        panoramas=panoramas,
        class_distribution=class_distribution,
        digest=digest,
    )


def _read_text(
    path: Path,
    subject: str,
    issues: list[ValidationIssue],
) -> tuple[str, str] | None:
    """Reads a UTF-8 text input and returns it with the digest of its bytes."""
    try:
        raw = path.read_bytes()
        text = raw.decode("utf-8-sig")
    except OSError as error:
        issues.append(ValidationIssue(subject, f"cannot be read: {error}"))
    except UnicodeDecodeError as error:
        issues.append(ValidationIssue(subject, f"is not valid UTF-8: {error}"))
    else:
        return text, _hash_bytes(raw)

    return None


def _read_catalog(path: Path, issues: list[ValidationIssue]) -> tuple[list[str], str]:
    """Reads one stable detector class key per line; its position is the class index."""
    if not path.is_file():
        issues.append(ValidationIssue(f"class catalog '{path.name}'", "is missing"))
        return [], ""

    read = _read_text(path, f"class catalog '{path.name}'", issues)
    if read is None:
        return [], ""

    text, digest = read
    catalog: list[str] = []
    seen: set[str] = set()

    for number, line in enumerate(text.splitlines(), 1):
        key = line.strip()
        if not key:
            issues.append(
                ValidationIssue(
                    f"class catalog '{path.name}' line {number}",
                    "is blank; every line is a class index",
                )
            )
            continue
        if key in seen:
            issues.append(
                ValidationIssue(
                    f"class catalog '{path.name}' line {number}",
                    f"duplicates the stable class key '{key}'",
                )
            )
            continue

        seen.add(key)
        catalog.append(key)

    if not catalog and not issues:
        issues.append(
            ValidationIssue(f"class catalog '{path.name}'", "contains no class keys")
        )

    return catalog, digest


def _read_panoramas(
    root: Path, issues: list[ValidationIssue]
) -> tuple[frozenset[str], str]:
    """Reads the source panoramas the export declares.

    The panorama files are not opened: identity and membership are all the split needs,
    and the pipeline must not retain or process runtime captures.
    """
    if not root.is_dir():
        issues.append(ValidationIssue(f"panoramas '{root.name}/'", "directory is missing"))
        return frozenset(), ""

    panorama_ids: list[str] = []
    seen: dict[str, str] = {}

    for path in sorted(root.rglob("*")):
        if not path.is_file() or path.name.startswith("."):
            continue

        panorama_id = path.stem
        relative = path.relative_to(root).as_posix()
        folded = panorama_id.casefold()

        if folded in seen:
            issues.append(
                ValidationIssue(
                    f"panoramas '{root.name}/{relative}'",
                    f"duplicates source panorama '{panorama_id}' "
                    f"(also '{seen[folded]}')",
                )
            )
            continue

        seen[folded] = relative
        panorama_ids.append(panorama_id)

    if not panorama_ids and not issues:
        issues.append(
            ValidationIssue(
                f"panoramas '{root.name}/'", "contains no source panoramas"
            )
        )

    digest = hashlib.sha256()
    for panorama_id in sorted(panorama_ids):
        _feed(digest, "panorama", panorama_id)

    return frozenset(panorama_ids), digest.hexdigest()


def _read_provenance(
    path: Path,
    panorama_ids: frozenset[str],
    issues: list[ValidationIssue],
) -> tuple[dict[str, str], str]:
    """Reads the mandatory crop-to-source-panorama mapping."""
    if not path.is_file():
        issues.append(ValidationIssue(path.name, "is missing"))
        return {}, ""

    read = _read_text(path, path.name, issues)
    if read is None:
        return {}, ""

    text, digest = read
    reader = csv.reader(io.StringIO(text, newline=""))

    try:
        header = next(reader)
    except StopIteration:
        issues.append(ValidationIssue(path.name, "is empty"))
        return {}, digest

    header = [column.strip() for column in header]
    try:
        crop_column = header.index("crop")
        panorama_column = header.index("source_panorama")
    except ValueError:
        issues.append(
            ValidationIssue(
                f"{path.name} header",
                "must contain 'crop' and 'source_panorama' columns",
            )
        )
        return {}, digest

    subject = path.name
    required_column = max(crop_column, panorama_column)
    provenance: dict[str, str] = {}
    data_rows = 0

    for row in reader:
        number = reader.line_num
        if not row:
            continue

        data_rows += 1

        if len(row) <= required_column:
            issues.append(
                ValidationIssue(
                    f"{subject} line {number}",
                    "does not have a value for every required column",
                )
            )
            continue

        crop = row[crop_column].strip()
        panorama = row[panorama_column].strip()

        if not crop:
            issues.append(
                ValidationIssue(
                    f"{subject} line {number}",
                    "names no crop",
                )
            )
            continue

        if not panorama:
            issues.append(
                ValidationIssue(
                    f"crop '{crop}'",
                    f"has no source panorama in provenance.csv line {number}",
                )
            )
            continue

        if crop in provenance:
            issues.append(
                ValidationIssue(
                    f"{subject} line {number}",
                    f"duplicates crop '{crop}'",
                )
            )
            continue

        if panorama not in panorama_ids:
            issues.append(
                ValidationIssue(
                    f"crop '{crop}'",
                    f"names source panorama '{panorama}', which the export does not contain",
                )
            )

        provenance[crop] = panorama

    if data_rows == 0:
        issues.append(
            ValidationIssue(
                subject,
                "contains no crop-to-source-panorama rows",
            )
        )

    return provenance, digest


def _scan_images(export: Path, issues: list[ValidationIssue]) -> list[_Image]:
    root = export / IMAGES_DIRECTORY

    if not root.is_dir():
        issues.append(
            ValidationIssue(f"images '{IMAGES_DIRECTORY}/'", "directory is missing")
        )
        return []

    images: list[_Image] = []
    seen: dict[str, list[tuple[str, str]]] = defaultdict(list)

    for path in sorted(root.rglob("*")):
        if not path.is_file() or path.name.startswith("."):
            continue
        if path.suffix.lower() not in IMAGE_SUFFIXES:
            issues.append(
                ValidationIssue(
                    f"image '{path.relative_to(export).as_posix()}'",
                    "has an unsupported image type; expected one of "
                    + ", ".join(sorted(IMAGE_SUFFIXES)),
                )
            )
            continue

        name = Path(path.relative_to(root).with_suffix("")).name
        image = _Image(
            name=name,
            path=path,
            relative_path=path.relative_to(export).as_posix(),
        )
        images.append(image)
        seen[name.casefold()].append((name, image.relative_path))

    for entries in seen.values():
        if len(entries) < 2:
            continue
        for name, relative_path in entries:
            others = ", ".join(
                repr(other) for _, other in entries if other != relative_path
            )
            issues.append(
                ValidationIssue(
                    f"crop '{name}'",
                    "duplicate crop name; would collide with "
                    + (others or "another crop"),
                )
            )

    if not images and not issues:
        issues.append(
            ValidationIssue(f"images '{IMAGES_DIRECTORY}/'", "contains no crops")
        )

    expected_labels = {
        _label_path(export, image).relative_to(export).as_posix().casefold()
        for image in images
    }
    _reject_orphan_labels(export, expected_labels, issues)

    return images


def _reject_duplicate_image_content(
    image_hashes: Mapping[str, str],
    issues: list[ValidationIssue],
) -> None:
    """Exact duplicate crops are leakage waiting to be split across panoramas."""
    by_digest: dict[str, list[str]] = defaultdict(list)

    for crop_name, digest in image_hashes.items():
        by_digest[digest].append(crop_name)

    for names in by_digest.values():
        if len(names) < 2:
            continue
        for name in sorted(names):
            others = ", ".join(repr(other) for other in sorted(names) if other != name)
            issues.append(
                ValidationIssue(
                    f"crop '{name}'",
                    "duplicate image content; identical pixels are also used by "
                    + others,
                )
            )


def _reject_orphan_labels(
    export: Path,
    expected_labels: set[str],
    issues: list[ValidationIssue],
) -> None:
    root = export / LABELS_DIRECTORY

    if not root.is_dir():
        issues.append(
            ValidationIssue(f"labels '{LABELS_DIRECTORY}/'", "directory is missing")
        )
        return

    for path in sorted(root.rglob("*")):
        if not path.is_file() or path.name.startswith("."):
            continue
        if path.suffix.lower() != ".txt":
            continue

        relative = path.relative_to(export).as_posix()
        if relative.casefold() not in expected_labels:
            name = Path(relative).relative_to(LABELS_DIRECTORY).with_suffix("").as_posix()
            issues.append(
                ValidationIssue(
                    f"label '{relative}'",
                    f"has no crop image for '{name}'",
                )
            )


def _validate_crop(
    image: _Image,
    label_path: Path,
    label_relative: str,
    catalog: Sequence[str],
    source_panorama: str,
    issues: list[ValidationIssue],
) -> _ValidatedCrop:
    image_hash = _validate_image(image, issues)

    if not label_path.is_file():
        issues.append(
            ValidationIssue(
                f"label '{label_relative}'",
                f"is missing for crop '{image.name}'",
            )
        )
        return _ValidatedCrop(
            _crop(image, label_relative, source_panorama, ()), image_hash, None
        )

    try:
        raw = label_path.read_bytes()
        text = raw.decode("utf-8-sig")
    except (OSError, UnicodeDecodeError) as error:
        issues.append(
            ValidationIssue(
                f"label '{label_relative}'",
                f"cannot be read for crop '{image.name}': {error}",
            )
        )
        return _ValidatedCrop(
            _crop(image, label_relative, source_panorama, ()), image_hash, None
        )

    boxes = _parse_boxes(text, image.name, label_relative, catalog, issues)

    return _ValidatedCrop(
        _crop(image, label_relative, source_panorama, boxes),
        image_hash,
        _hash_bytes(raw),
    )


def _crop(
    image: _Image,
    label_relative: str,
    source_panorama: str,
    boxes: tuple[BoundingBox, ...],
) -> Crop:
    return Crop(
        name=image.name,
        image=image.relative_path,
        label=label_relative,
        source_panorama=source_panorama,
        boxes=boxes,
    )


def _validate_image(image: _Image, issues: list[ValidationIssue]) -> str | None:
    try:
        data = image.path.read_bytes()
    except OSError as error:
        issues.append(
            ValidationIssue(
                f"image '{image.relative_path}'",
                f"cannot be read for crop '{image.name}': {error}",
            )
        )
        return None

    if not _is_readable_image(data):
        issues.append(
            ValidationIssue(
                f"image '{image.relative_path}'",
                f"is not a readable image for crop '{image.name}'",
            )
        )
        return None

    return _hash_bytes(data)


def _is_readable_image(data: bytes) -> bool:
    """Decodes the pixels, not just the header: training must be able to open it."""
    try:
        with Image.open(BytesIO(data)) as image:
            image.load()
    except (Image.DecompressionBombError, OSError, SyntaxError, UnidentifiedImageError):
        return False

    return True


def _parse_boxes(
    text: str,
    crop_name: str,
    label_relative: str,
    catalog: Sequence[str],
    issues: list[ValidationIssue],
) -> tuple[BoundingBox, ...]:
    lines = text.splitlines()
    # A final newline is not an annotation line. Interior blank lines are malformed.
    while lines and not lines[-1].strip():
        lines.pop()

    boxes: list[BoundingBox] = []

    for number, line in enumerate(lines, 1):
        subject = f"label '{label_relative}' line {number} (crop '{crop_name}')"
        line_issues: list[ValidationIssue] = []
        fields = line.split()

        if len(fields) != 5:
            issues.append(
                ValidationIssue(
                    subject,
                    "expected 5 fields "
                    "(class_index x_center y_center width height), "
                    f"got {len(fields)}",
                )
            )
            continue

        class_index = _class_index(fields[0], catalog, subject, line_issues)
        coordinates: dict[str, float] = {}

        for name, raw in zip(
            ("x_center", "y_center", "width", "height"), fields[1:], strict=True
        ):
            try:
                value = float(raw)
            except ValueError:
                line_issues.append(
                    ValidationIssue(subject, f"{name} is not a number: '{raw}'")
                )
                continue

            if not math.isfinite(value):
                line_issues.append(
                    ValidationIssue(subject, f"{name} is not a finite number: '{raw}'")
                )
                continue

            coordinates[name] = value

        if len(coordinates) == 4:
            x_center = coordinates["x_center"]
            y_center = coordinates["y_center"]
            width = coordinates["width"]
            height = coordinates["height"]

            for name, value in (("x_center", x_center), ("y_center", y_center)):
                if not 0.0 <= value <= 1.0:
                    line_issues.append(
                        ValidationIssue(
                            subject,
                            f"{name} {value} is outside the normalized 0..1 range",
                        )
                    )

            for name, value in (("width", width), ("height", height)):
                if value > 1.0:
                    line_issues.append(
                        ValidationIssue(
                            subject,
                            f"{name} {value} is outside the normalized 0..1 range",
                        )
                    )

            if width < 0.0 or height < 0.0:
                line_issues.append(
                    ValidationIssue(
                        subject,
                        "box is inverted; width and height must be positive",
                    )
                )
            elif width == 0.0 or height == 0.0:
                line_issues.append(
                    ValidationIssue(
                        subject,
                        "box has zero area; width and height must be greater than zero",
                    )
                )
            elif not line_issues and not _fits_normalized_image(
                x_center, y_center, width, height
            ):
                line_issues.append(
                    ValidationIssue(
                        subject,
                        "box extends outside the normalized image",
                    )
                )

            if not line_issues and class_index is not None:
                boxes.append(
                    BoundingBox(
                        class_index=class_index,
                        class_key=catalog[class_index],
                        x_center=x_center,
                        y_center=y_center,
                        width=width,
                        height=height,
                    )
                )

        issues.extend(line_issues)

    return tuple(boxes)


def _class_index(
    raw: str,
    catalog: Sequence[str],
    subject: str,
    issues: list[ValidationIssue],
) -> int | None:
    try:
        class_index = int(raw)
    except ValueError:
        issues.append(
            ValidationIssue(subject, f"class index is not an integer: '{raw}'")
        )
        return None

    if not 0 <= class_index < len(catalog):
        issues.append(
            ValidationIssue(
                subject,
                f"class index {class_index} is outside the class catalog "
                f"of {len(catalog)} entries",
            )
        )
        return None

    return class_index


def _fits_normalized_image(
    x_center: float,
    y_center: float,
    width: float,
    height: float,
) -> bool:
    return (
        x_center - width / 2 >= -COORDINATE_TOLERANCE
        and y_center - height / 2 >= -COORDINATE_TOLERANCE
        and x_center + width / 2 <= 1.0 + COORDINATE_TOLERANCE
        and y_center + height / 2 <= 1.0 + COORDINATE_TOLERANCE
    )


def _label_path(export: Path, image: _Image) -> Path:
    relative = image.path.relative_to(export / IMAGES_DIRECTORY).with_suffix(".txt")

    return export / LABELS_DIRECTORY / relative


def _group_by_panorama(
    panorama_ids: frozenset[str], crops: Sequence[Crop]
) -> dict[str, tuple[str, ...]]:
    groups: dict[str, list[str]] = {
        panorama_id: [] for panorama_id in sorted(panorama_ids)
    }

    for crop in crops:
        groups[crop.source_panorama].append(crop.name)

    return {panorama: tuple(names) for panorama, names in groups.items()}


def _class_distribution(
    catalog: Sequence[str], crops: Sequence[Crop]
) -> dict[str, int]:
    distribution = {key: 0 for key in catalog}

    for crop in crops:
        for box in crop.boxes:
            distribution[box.class_key] += 1

    return distribution


def _digest(
    *,
    catalog_digest: str,
    provenance_digest: str,
    panorama_digest: str,
    crops: Sequence[Crop],
    image_hashes: Mapping[str, str],
    label_hashes: Mapping[str, str],
) -> str:
    digest = hashlib.sha256()
    _feed(digest, "schema_version", str(SCHEMA_VERSION))
    _feed(digest, "class_catalog", catalog_digest)
    _feed(digest, "provenance", provenance_digest)
    _feed(digest, "panoramas", panorama_digest)

    for crop in crops:
        _feed(digest, "crop", crop.name)
        _feed(digest, "image_path", crop.image)
        _feed(digest, "image", image_hashes.get(crop.name, ""))
        _feed(digest, "label_path", crop.label)
        _feed(digest, "label", label_hashes.get(crop.name, ""))
        _feed(digest, "source_panorama", crop.source_panorama)

    return f"sha256:{digest.hexdigest()}"


def _feed(digest: _Hash, label: str, value: str) -> None:
    encoded = value.encode("utf-8")
    digest.update(f"{label} {len(encoded)}\n".encode("ascii"))
    digest.update(encoded)


def _hash_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()
