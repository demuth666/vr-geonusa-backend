"""Locating the checkout these tools run from, for their ignored data areas."""

from pathlib import Path


def repository_root() -> Path:
    """The checkout root that holds the ignored datasets and artifacts directories."""
    package = Path(__file__).resolve().parent

    for candidate in (package, *package.parents):
        if (candidate / ".git").exists():
            return candidate

    # The checkout layout puts this module at services/ml. Shallow layouts (for instance
    # a container's /app) have no `.git` ancestor, so fall back to the checkout root its
    # `services` directory implies, or to the module's own directory.
    if len(package.parents) >= 2 and (package.parents[1] / "services").is_dir():
        return package.parents[1]

    return package
