"""Stable digest helpers shared by the dataset and training stages."""

import hashlib
from pathlib import Path
from typing import Protocol


class Hash(Protocol):
    def update(self, data: bytes) -> None: ...


def feed(digest: Hash, label: str, value: str) -> None:
    """Feeds a length-prefixed field so concatenated values cannot collide."""
    encoded = value.encode("utf-8")
    digest.update(f"{label} {len(encoded)}\n".encode("ascii"))
    digest.update(encoded)


def file_digest(path: Path) -> str:
    """The SHA-256 hex digest of a file's bytes, read in chunks."""
    digest = hashlib.sha256()

    with Path(path).open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)

    return digest.hexdigest()
