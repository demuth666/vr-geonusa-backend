from dataclasses import dataclass
from io import BytesIO

from PIL import Image, UnidentifiedImageError

PADDING = (114, 114, 114)
"""Border colour the YOLO family is trained with, so padded edges read as background."""

Box = tuple[float, float, float, float]
"""Left, top, right, bottom of a detection, in the coordinates of the image it was found in."""


def is_valid_image(image: bytes) -> bool:
    try:
        with Image.open(BytesIO(image)) as uploaded_image:
            uploaded_image.verify()
    except (Image.DecompressionBombError, OSError, SyntaxError, UnidentifiedImageError):
        return False

    return True


@dataclass(frozen=True)
class Letterbox:
    """Where a submitted image landed inside the square model input."""

    scale: float
    pad_x: int
    pad_y: int
    width: int
    height: int

    def to_original_box(self, box: Box) -> list[int]:
        """Maps a box on the model input back onto the submitted image.

        Coordinates the model places outside the image — over the padding, or past an
        edge — are clamped to the image, so every box is a whole number of pixels inside
        the submitted image.
        """
        return [
            _clamp(round((box[0] - self.pad_x) / self.scale), self.width),
            _clamp(round((box[1] - self.pad_y) / self.scale), self.height),
            _clamp(round((box[2] - self.pad_x) / self.scale), self.width),
            _clamp(round((box[3] - self.pad_y) / self.scale), self.height),
        ]


def letterbox(image: Image.Image, size: int) -> tuple[Image.Image, Letterbox]:
    """Fits a submitted image into a `size` x `size` square without distorting it.

    The image keeps its aspect ratio and is centred on a padded square, which is what the
    detector's model expects. The returned Letterbox records the transform so detections
    can be mapped back to the submitted image's own pixels.
    """
    width, height = image.size
    scale = min(size / width, size / height)
    # A very lopsided image — Laravel accepts anything up to 4096x4096 — scales its short
    # edge below one pixel, which cannot be resized. Keep the edge there instead.
    fitted = (max(1, round(width * scale)), max(1, round(height * scale)))
    pad_x = (size - fitted[0]) // 2
    pad_y = (size - fitted[1]) // 2

    canvas = Image.new("RGB", (size, size), PADDING)
    canvas.paste(image.convert("RGB").resize(fitted, Image.Resampling.BILINEAR), (pad_x, pad_y))

    return canvas, Letterbox(
        scale=scale,
        pad_x=pad_x,
        pad_y=pad_y,
        width=width,
        height=height,
    )


def _clamp(value: int, limit: int) -> int:
    return min(max(value, 0), limit)
