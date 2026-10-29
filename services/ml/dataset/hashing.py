"""Stable digest helpers shared by the dataset manifest and split stages."""

from typing import Protocol


class Hash(Protocol):
    def update(self, data: bytes) -> None: ...


def feed(digest: Hash, label: str, value: str) -> None:
    """Feeds a length-prefixed field so concatenated values cannot collide."""
    encoded = value.encode("utf-8")
    digest.update(f"{label} {len(encoded)}\n".encode("ascii"))
    digest.update(encoded)
