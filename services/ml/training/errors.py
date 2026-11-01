"""The one failure a training run reports to its operator."""


class TrainingError(ValueError):
    """Raised when a dataset or configuration cannot produce a trustworthy run."""
