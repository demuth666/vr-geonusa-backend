"""Validation and normalization for an external, object-level crop export.

The runtime service never imports this package: dataset preparation reads an export
from disk, proves it is fit to become a training dataset, and writes a manifest for the
later split and training stages.
"""

from dataset.manifest import (
    BoundingBox,
    Crop,
    DatasetManifest,
    write_manifest,
)
from dataset.validation import (
    DatasetValidationError,
    ValidationIssue,
    prepare_export,
)

__all__ = [
    "BoundingBox",
    "Crop",
    "DatasetManifest",
    "DatasetValidationError",
    "ValidationIssue",
    "prepare_export",
    "write_manifest",
]
