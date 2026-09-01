from app.preprocessing import is_valid_image


def test_is_valid_image_rejects_a_truncated_png() -> None:
    assert not is_valid_image(b"\x89PNG\r\n\x1a\n")
