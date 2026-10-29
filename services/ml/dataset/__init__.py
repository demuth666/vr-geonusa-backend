"""Validation, normalization, and panorama-grouped splitting for a crop export.

The runtime service never imports this package: dataset preparation reads an export
from disk, proves it is fit to become a training dataset, and materializes a
leakage-free split with its own manifest for the later training stage.
"""

from dataset.manifest import (
    BoundingBox,
    Crop,
    DatasetManifest,
    DatasetManifestError,
    read_manifest,
    write_manifest,
)
from dataset.split import (
    DEFAULT_DATASET_NAME,
    DEFAULT_RATIOS,
    DEFAULT_SEED,
    SPLITS,
    SPLIT_SCHEMA_VERSION,
    SplitError,
    SplitManifest,
    SplitSummary,
    default_output,
    split_dataset,
)
from dataset.validation import (
    DatasetValidationError,
    ValidationIssue,
    prepare_export,
)

__all__ = [
    "BoundingBox",
    "Crop",
    "DEFAULT_DATASET_NAME",
    "DEFAULT_RATIOS",
    "DEFAULT_SEED",
    "DatasetManifest",
    "DatasetManifestError",
    "DatasetValidationError",
    "SPLITS",
    "SPLIT_SCHEMA_VERSION",
    "SplitError",
    "SplitManifest",
    "SplitSummary",
    "ValidationIssue",
    "default_output",
    "prepare_export",
    "read_manifest",
    "split_dataset",
    "write_manifest",
]
