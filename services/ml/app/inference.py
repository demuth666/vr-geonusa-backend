from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from typing import Protocol

from PIL import Image


@dataclass(frozen=True)
class RawDetection:
    """One detection, in the coordinates of the image the model was given."""

    class_index: int
    confidence: float
    box: tuple[float, float, float, float]


class InferenceBackend(Protocol):
    """A loaded model, able to run one inference pass over an image sized for it.

    Backends are substituted in tests, which is what keeps the default test suite free of
    model artifacts and of the inference runtime.
    """

    @property
    def input_size(self) -> int:
        """Edge length of the square image the model expects."""
        ...

    @property
    def class_names(self) -> Mapping[int, str]:
        """The model's own label names, keyed by the class index it reports."""
        ...

    def infer(self, image: Image.Image) -> Sequence[RawDetection]:
        """Detects objects in an image that is already `input_size` x `input_size`."""
        ...
