from PIL import Image

from app.preprocessing import is_valid_image, letterbox


def image(width: int, height: int) -> Image.Image:
    return Image.new("RGB", (width, height), (10, 20, 30))


def test_is_valid_image_rejects_a_truncated_png() -> None:
    assert not is_valid_image(b"\x89PNG\r\n\x1a\n")


def test_letterbox_fits_a_wide_image_onto_a_padded_square() -> None:
    model_input, placement = letterbox(image(800, 400), 640)

    assert model_input.size == (640, 640)
    assert placement.scale == 0.8
    assert (placement.pad_x, placement.pad_y) == (0, 160)
    assert (placement.width, placement.height) == (800, 400)


def test_letterbox_leaves_a_square_image_unpadded() -> None:
    model_input, placement = letterbox(image(512, 512), 640)

    assert model_input.size == (640, 640)
    assert (placement.pad_x, placement.pad_y) == (0, 0)


def test_letterbox_centres_a_tall_image_with_equal_side_padding() -> None:
    model_input, placement = letterbox(image(320, 640), 640)

    assert model_input.size == (640, 640)
    assert (placement.pad_x, placement.pad_y) == (160, 0)


def test_letterbox_survives_a_single_pixel_high_image() -> None:
    # Laravel accepts any image up to 4096x4096, so a single-pixel edge has to survive
    # scaling, where it would otherwise round down to no pixels at all.
    model_input, placement = letterbox(image(4096, 1), 640)

    assert model_input.size == (640, 640)
    assert (placement.pad_x, placement.pad_y) == (0, 319)
    assert placement.to_original_box((0.0, 319.0, 640.0, 320.0)) == [0, 0, 4096, 1]


def test_a_box_on_the_model_input_maps_back_onto_the_whole_submitted_image() -> None:
    _, placement = letterbox(image(800, 400), 640)

    assert placement.to_original_box((0.0, 160.0, 640.0, 480.0)) == [0, 0, 800, 400]


def test_a_box_extends_only_as_far_as_the_submitted_image() -> None:
    _, placement = letterbox(image(800, 400), 640)

    assert placement.to_original_box((-40.0, 0.0, 900.0, 700.0)) == [0, 0, 800, 400]


def test_mapped_boxes_are_whole_pixels_in_the_submitted_image() -> None:
    _, placement = letterbox(image(1000, 750), 640)

    box = placement.to_original_box((100.5, 260.25, 500.75, 480.5))

    assert all(isinstance(coordinate, int) for coordinate in box)
    assert 0 <= box[0] <= box[2] <= 1000
    assert 0 <= box[1] <= box[3] <= 750
