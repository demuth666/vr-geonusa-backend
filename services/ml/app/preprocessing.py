from io import BytesIO

from PIL import Image, UnidentifiedImageError


def is_valid_image(image: bytes) -> bool:
    try:
        with Image.open(BytesIO(image)) as uploaded_image:
            uploaded_image.verify()
    except (Image.DecompressionBombError, OSError, SyntaxError, UnidentifiedImageError):
        return False

    return True
