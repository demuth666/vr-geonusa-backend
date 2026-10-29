import csv
import json
import os
import subprocess
import sys
from io import BytesIO
from pathlib import Path

import pytest
from PIL import Image

from dataset import DatasetValidationError, prepare_export

SERVICES_ML = Path(__file__).resolve().parents[1]


def build_export(root: Path) -> Path:
    """A small, valid YOLO-style export with object-level boxes and provenance."""
    (root / "images").mkdir(parents=True)
    (root / "labels").mkdir()
    (root / "panoramas").mkdir()

    (root / "panoramas" / "stupa-induk.jpg").write_bytes(b"not read by the pipeline")
    (root / "classes.txt").write_text("stupa\nrelief\n", encoding="utf-8")
    write_provenance(
        root,
        [
            ("stupa-01", "stupa-induk"),
            ("stupa-02", "stupa-induk"),
        ],
    )

    write_image(root / "images" / "stupa-01.png")
    (root / "labels" / "stupa-01.txt").write_text(
        "0 0.5 0.5 0.2 0.3\n1 0.25 0.25 0.1 0.1\n",
        encoding="utf-8",
    )

    write_image(root / "images" / "stupa-02.png", colour=(60, 40, 20))
    (root / "labels" / "stupa-02.txt").write_text("", encoding="utf-8")

    return root


def write_image(path: Path, colour: tuple[int, int, int] = (20, 40, 60)) -> None:
    Image.new("RGB", (16, 16), colour).save(path)


def write_provenance(root: Path, rows: list[tuple[str, str]]) -> None:
    with (root / "provenance.csv").open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(["crop", "source_panorama"])
        writer.writerows(rows)


def run_cli(export: Path, manifest_path: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            sys.executable,
            "-m",
            "dataset",
            "validate",
            str(export),
            "--output",
            str(manifest_path),
        ],
        cwd=SERVICES_ML,
        capture_output=True,
        text=True,
        env={**os.environ, "PYTHONPATH": str(SERVICES_ML)},
    )


def test_a_valid_export_becomes_a_normalized_manifest(tmp_path: Path) -> None:
    manifest = prepare_export(build_export(tmp_path / "export"))

    assert manifest.schema_version == 1
    assert manifest.class_catalog == ("stupa", "relief")
    assert [crop.name for crop in manifest.crops] == ["stupa-01", "stupa-02"]

    first = manifest.crops[0]
    assert first.source_panorama == "stupa-induk"
    assert [box.class_key for box in first.boxes] == ["stupa", "relief"]
    assert first.boxes[0].x_center == 0.5
    assert first.boxes[0].width == 0.2

    assert manifest.panoramas == {"stupa-induk": ("stupa-01", "stupa-02")}
    assert manifest.class_distribution == {"stupa": 1, "relief": 1}
    assert manifest.crops[1].boxes == ()
    assert manifest.digest.startswith("sha256:")


def validation_error(export: Path) -> DatasetValidationError:
    with pytest.raises(DatasetValidationError) as raised:
        prepare_export(export)

    return raised.value


def assert_issue(error: DatasetValidationError, *fragments: str) -> None:
    messages = [str(issue) for issue in error.issues]

    assert any(
        all(fragment in message for fragment in fragments) for message in messages
    ), messages


def test_a_missing_label_names_the_label_and_the_crop(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").unlink()

    error = validation_error(export)

    assert_issue(error, "label 'labels/stupa-01.txt'", "missing", "stupa-01")


def test_a_label_without_an_image_names_the_label(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "images" / "stupa-01.png").unlink()

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt'",
        "has no crop image",
    )


def test_an_unreadable_image_names_the_image_and_the_crop(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "images" / "stupa-01.png").write_bytes(b"not an image")

    error = validation_error(export)

    assert_issue(
        error,
        "image 'images/stupa-01.png'",
        "not a readable image",
        "stupa-01",
    )


def test_a_truncated_image_that_only_fails_full_decode_is_unreadable(
    tmp_path: Path,
) -> None:
    export = build_export(tmp_path / "export")
    buffer = BytesIO()
    Image.new("RGB", (16, 16), (1, 2, 3)).save(buffer, "JPEG")
    (export / "images" / "stupa-01.png").write_bytes(buffer.getvalue()[:-2])

    error = validation_error(export)

    assert_issue(
        error,
        "image 'images/stupa-01.png'",
        "not a readable image",
    )


def test_a_label_with_a_utf8_bom_is_still_readable(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_bytes(
        b"\xef\xbb\xbf" + b"0 0.5 0.5 0.2 0.3\n"
    )

    manifest = prepare_export(export)

    assert len(manifest.crops[0].boxes) == 1


def test_an_unreadable_label_names_the_label_and_the_crop(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_bytes(b"\xff\xfe\x00")

    error = validation_error(export)

    assert_issue(error, "label 'labels/stupa-01.txt'", "cannot be read", "stupa-01")


def test_a_malformed_label_line_names_the_line_and_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.5 0.5 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "expected 5 fields",
    )


def test_a_blank_label_line_is_malformed(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.5 0.5 0.2 0.2\n\n1 0.25 0.25 0.1 0.1\n",
        encoding="utf-8",
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 2",
        "expected 5 fields",
    )


def test_a_class_index_outside_the_catalog_names_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "9 0.5 0.5 0.2 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "class index 9",
        "outside the class catalog",
    )


def test_a_non_normalized_coordinate_names_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "0 1.2 0.5 0.2 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "x_center",
        "outside the normalized 0..1 range",
    )


def test_a_box_extending_past_the_image_edge_names_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.05 0.5 0.2 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "extends outside the normalized image",
    )


def test_an_inverted_box_names_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.5 0.5 -0.2 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "box is inverted",
    )


def test_a_zero_area_box_names_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.5 0.5 0 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "zero area",
    )


def test_a_non_integer_class_index_names_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "stupa 0.5 0.5 0.2 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "class index is not an integer",
    )


def test_a_non_finite_coordinate_names_the_rule(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text(
        "0 nan 0.5 0.2 0.2\n", encoding="utf-8"
    )

    error = validation_error(export)

    assert_issue(
        error,
        "label 'labels/stupa-01.txt' line 1",
        "x_center is not a finite number",
    )


def test_an_unsupported_file_in_the_image_directory_names_the_image(
    tmp_path: Path,
) -> None:
    export = build_export(tmp_path / "export")
    (export / "images" / "stupa-03.gif").write_bytes(b"GIF89a")

    error = validation_error(export)

    assert_issue(
        error,
        "image 'images/stupa-03.gif'",
        "unsupported image type",
    )


def test_a_crop_without_provenance_names_the_crop(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    write_provenance(export, [("stupa-02", "stupa-induk")])

    error = validation_error(export)

    assert_issue(error, "crop 'stupa-01'", "no source panorama")


def test_provenance_is_mandatory(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "provenance.csv").unlink()

    error = validation_error(export)

    assert_issue(error, "provenance.csv", "is missing")


def test_provenance_naming_an_unknown_panorama_names_the_crop(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    write_provenance(
        export,
        [
            ("stupa-01", "missing-panorama"),
            ("stupa-02", "stupa-induk"),
        ],
    )

    error = validation_error(export)

    assert_issue(
        error,
        "crop 'stupa-01'",
        "source panorama 'missing-panorama'",
        "does not contain",
    )


def test_provenance_naming_an_unknown_crop_names_the_entry(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    write_provenance(
        export,
        [
            ("stupa-01", "stupa-induk"),
            ("stupa-02", "stupa-induk"),
            ("ghost", "stupa-induk"),
        ],
    )

    error = validation_error(export)

    assert_issue(error, "provenance.csv entry 'ghost'", "does not contain")


def test_duplicate_provenance_rows_are_rejected(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    write_provenance(
        export,
        [
            ("stupa-01", "stupa-induk"),
            ("stupa-01", "stupa-induk"),
            ("stupa-02", "stupa-induk"),
        ],
    )

    error = validation_error(export)

    assert_issue(error, "provenance.csv line 3", "duplicates crop 'stupa-01'")


def test_duplicate_crop_names_that_would_collide_are_rejected(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    write_image(export / "images" / "stupa-01.jpg")

    error = validation_error(export)

    assert_issue(error, "crop 'stupa-01'", "duplicate crop name")


def test_same_named_crops_in_different_directories_are_duplicates(
    tmp_path: Path,
) -> None:
    export = build_export(tmp_path / "export")
    (export / "images" / "north").mkdir()
    (export / "labels" / "north").mkdir()
    write_image(export / "images" / "north" / "stupa-01.png")
    (export / "labels" / "north" / "stupa-01.txt").write_text("", encoding="utf-8")

    error = validation_error(export)

    assert_issue(error, "crop 'stupa-01'", "duplicate crop name")


def test_identical_crop_content_under_another_name_is_rejected(
    tmp_path: Path,
) -> None:
    export = build_export(tmp_path / "export")
    pixels = (export / "images" / "stupa-01.png").read_bytes()
    (export / "images" / "stupa-03.png").write_bytes(pixels)
    (export / "labels" / "stupa-03.txt").write_text("", encoding="utf-8")
    write_provenance(
        export,
        [
            ("stupa-01", "stupa-induk"),
            ("stupa-02", "stupa-induk"),
            ("stupa-03", "stupa-induk"),
        ],
    )

    error = validation_error(export)

    assert_issue(
        error,
        "crop 'stupa-01'",
        "duplicate image content",
        "stupa-03",
    )


def test_an_export_with_no_object_boxes_is_not_a_training_dataset(
    tmp_path: Path,
) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text("", encoding="utf-8")

    error = validation_error(export)

    assert_issue(error, "export", "contains no object-level bounding boxes")


def test_panorama_grouping_comes_from_provenance_not_filenames(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "panoramas" / "pelataran-timur.jpg").write_bytes(b"not read")
    write_provenance(
        export,
        [
            ("stupa-01", "stupa-induk"),
            ("stupa-02", "pelataran-timur"),
        ],
    )

    manifest = prepare_export(export)

    assert manifest.panoramas == {
        "pelataran-timur": ("stupa-02",),
        "stupa-induk": ("stupa-01",),
    }


def test_panorama_declarations_can_be_nested(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    (export / "panoramas" / "north").mkdir()
    (export / "panoramas" / "north" / "pelataran-timur.jpg").write_bytes(b"not read")
    write_provenance(
        export,
        [
            ("stupa-01", "stupa-induk"),
            ("stupa-02", "pelataran-timur"),
        ],
    )

    manifest = prepare_export(export)

    assert manifest.panoramas["pelataran-timur"] == ("stupa-02",)


def test_the_digest_is_stable_and_changes_with_the_inputs(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")

    first = prepare_export(export)
    assert prepare_export(export).digest == first.digest

    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.4 0.4 0.2 0.2\n", encoding="utf-8"
    )

    assert prepare_export(export).digest != first.digest


def test_the_cli_writes_a_manifest_and_exits_zero(tmp_path: Path) -> None:
    export = build_export(tmp_path / "export")
    manifest_path = tmp_path / "dataset-manifest.json"

    result = run_cli(export, manifest_path)

    assert result.returncode == 0, result.stderr
    payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    assert payload["schema_version"] == 1
    assert [crop["name"] for crop in payload["crops"]] == ["stupa-01", "stupa-02"]


def test_the_cli_exits_nonzero_and_writes_nothing_for_an_invalid_export(
    tmp_path: Path,
) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text("broken\n", encoding="utf-8")
    manifest_path = tmp_path / "dataset-manifest.json"

    result = run_cli(export, manifest_path)

    assert result.returncode == 1
    assert "label 'labels/stupa-01.txt' line 1" in result.stderr
    assert "expected 5 fields" in result.stderr
    assert not manifest_path.exists()


def test_the_cli_does_not_silently_replace_an_existing_manifest_on_failure(
    tmp_path: Path,
) -> None:
    export = build_export(tmp_path / "export")
    (export / "labels" / "stupa-01.txt").write_text("broken\n", encoding="utf-8")
    manifest_path = tmp_path / "dataset-manifest.json"
    manifest_path.write_text('{"previous": true}\n', encoding="utf-8")

    result = run_cli(export, manifest_path)

    assert result.returncode == 1
    assert "left untouched" in result.stderr
    assert json.loads(manifest_path.read_text(encoding="utf-8")) == {"previous": True}


def test_the_dataset_package_needs_no_runtime_or_training_stack() -> None:
    script = "\n".join(
        [
            "import sys",
            "import dataset",
            "forbidden = {'fastapi', 'ultralytics', 'torch', 'app'}",
            "loaded = forbidden.intersection(sys.modules)",
            "assert not loaded, loaded",
        ]
    )

    result = subprocess.run(
        [sys.executable, "-c", script],
        cwd=SERVICES_ML,
        capture_output=True,
        text=True,
        env={**os.environ, "PYTHONPATH": str(SERVICES_ML)},
    )

    assert result.returncode == 0, result.stderr
