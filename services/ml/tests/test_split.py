import csv
import json
import os
import subprocess
import sys
from pathlib import Path

import pytest
from PIL import Image

import dataset.split as split_module
from dataset import (
    DatasetManifestError,
    SplitError,
    default_output,
    prepare_export,
    read_manifest,
    split_dataset,
    write_manifest,
)

SERVICES_ML = Path(__file__).resolve().parents[1]

PANORAMAS = {
    "stupa-induk": ("stupa-01", "stupa-02", "stupa-03"),
    "pelataran-timur": ("pelataran-01", "pelataran-02", "pelataran-03"),
    "relief-panel": ("relief-01", "relief-02"),
    "lorong-utara": ("lorong-01", "lorong-02"),
}
"""A fixture where every panorama contributes more than one crop."""


def build_export(
    root: Path, groups: dict[str, tuple[str, ...]] | None = None
) -> Path:
    groups = groups or PANORAMAS

    (root / "images").mkdir(parents=True)
    (root / "labels").mkdir()
    (root / "panoramas").mkdir()
    (root / "classes.txt").write_text("stupa\nrelief\n", encoding="utf-8")

    rows: list[tuple[str, str]] = []
    colour = 0

    for panorama, crops in groups.items():
        (root / "panoramas" / f"{panorama}.jpg").write_bytes(b"not read by the pipeline")

        for crop in crops:
            write_image(
                root / "images" / f"{crop}.png",
                colour=(colour * 7 % 256, colour * 13 % 256, colour * 17 % 256),
            )
            colour += 1

            label = "0 0.5 0.5 0.2 0.2\n"
            if colour % 3 == 0:
                label += "1 0.25 0.25 0.1 0.1\n"
            (root / "labels" / f"{crop}.txt").write_text(label, encoding="utf-8")
            rows.append((crop, panorama))

    with (root / "provenance.csv").open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(["crop", "source_panorama"])
        writer.writerows(rows)

    return root


def write_image(path: Path, colour: tuple[int, int, int]) -> None:
    Image.new("RGB", (16, 16), colour).save(path)


def export_and_manifest(tmp_path: Path, groups=None):
    export = build_export(tmp_path / "export", groups)
    manifest = prepare_export(export)
    manifest_path = tmp_path / "dataset-manifest.json"
    write_manifest(manifest, manifest_path)

    return export, manifest, manifest_path


def equal_groups(panoramas: int, crops: int = 2) -> dict[str, tuple[str, ...]]:
    return {
        f"panorama-{panorama}": tuple(
            f"crop-{panorama}-{crop}" for crop in range(crops)
        )
        for panorama in range(panoramas)
    }


def sized_groups(sizes: tuple[int, ...]) -> dict[str, tuple[str, ...]]:
    return {
        f"panorama-{panorama}": tuple(
            f"crop-{panorama}-{crop}" for crop in range(size)
        )
        for panorama, size in enumerate(sizes)
    }


def assignment(result) -> dict[str, str]:
    return {
        crop: split
        for split, summary in result.splits.items()
        for crop in summary.images
    }


def test_a_split_materializes_every_crop_in_exactly_one_split(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)
    output = tmp_path / "dataset"

    result = split_dataset(manifest, export, output)

    placed = assignment(result)
    assert sorted(placed) == sorted(crop.name for crop in manifest.crops)

    for split in ("train", "val", "test"):
        assert (output / "images" / split).is_dir()
        assert (output / "labels" / split).is_dir()

    for crop in manifest.crops:
        split = placed[crop.name]
        copied_image = output / "images" / split / f"{crop.name}{Path(crop.image).suffix}"
        copied_label = output / "labels" / split / f"{crop.name}.txt"

        assert copied_image.read_bytes() == (export / crop.image).read_bytes()
        assert copied_label.read_bytes() == (export / crop.label).read_bytes()


def test_crops_from_one_panorama_always_share_a_split(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)

    result = split_dataset(manifest, export, tmp_path / "dataset")
    placed = assignment(result)

    for crops in manifest.panoramas.values():
        assert len({placed[crop] for crop in crops}) == 1

    assert all(summary.panoramas for summary in result.splits.values())
    assert all(summary.images for summary in result.splits.values())


def test_the_same_manifest_and_seed_produce_the_same_split(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path, equal_groups(6))

    first = split_dataset(manifest, export, tmp_path / "first", seed=11)
    second = split_dataset(manifest, export, tmp_path / "second", seed=11)

    assert first.to_dict() == second.to_dict()
    assert assignment(first) == assignment(second)


def test_a_different_seed_produces_a_valid_but_different_split(
    tmp_path: Path,
) -> None:
    export, manifest, _ = export_and_manifest(tmp_path, equal_groups(6))

    first = split_dataset(manifest, export, tmp_path / "first", seed=1)
    second = split_dataset(manifest, export, tmp_path / "second", seed=2)

    assert first.digest != second.digest
    assert assignment(first) != assignment(second)

    for result in (first, second):
        placed = assignment(result)
        for crops in manifest.panoramas.values():
            assert len({placed[crop] for crop in crops}) == 1


def test_the_seed_can_move_whole_groups_with_distinct_sizes(
    tmp_path: Path,
) -> None:
    export, manifest, _ = export_and_manifest(tmp_path, sized_groups((4, 3, 2)))

    first = split_dataset(manifest, export, tmp_path / "first", seed=1)
    second = split_dataset(manifest, export, tmp_path / "second", seed=3)

    assert first.digest != second.digest
    assert assignment(first) != assignment(second)


def test_filling_an_empty_split_moves_the_smallest_whole_group(
    tmp_path: Path,
) -> None:
    export, manifest, _ = export_and_manifest(tmp_path, sized_groups((6, 5, 1, 1)))

    result = split_dataset(manifest, export, tmp_path / "dataset", seed=0)

    assert {
        split: summary.counts["images"]
        for split, summary in result.splits.items()
    } == {"train": 11, "val": 1, "test": 1}


def test_ratios_are_configurable_and_allocate_whole_groups(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path, equal_groups(10, crops=1))

    result = split_dataset(manifest, export, tmp_path / "dataset", ratios=(0.6, 0.2, 0.2))

    assert {
        split: summary.counts["images"]
        for split, summary in result.splits.items()
    } == {"train": 6, "val": 2, "test": 2}


def test_a_large_panorama_is_not_left_for_the_smallest_split(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path, sized_groups((6, 5, 1, 1)))

    result = split_dataset(
        manifest,
        export,
        tmp_path / "dataset",
        seed=4,
        ratios=(0.6, 0.25, 0.15),
    )

    assert result.splits["test"].counts["images"] <= 2


def test_the_split_manifest_records_the_inputs_and_the_result(
    tmp_path: Path,
) -> None:
    export, manifest, _ = export_and_manifest(tmp_path, equal_groups(10, crops=1))
    output = tmp_path / "dataset"

    result = split_dataset(
        manifest,
        export,
        output,
        seed=7,
        ratios=(0.6, 0.2, 0.2),
    )

    payload = json.loads((output / "split-manifest.json").read_text(encoding="utf-8"))
    assert payload == result.to_dict()
    assert payload["schema_version"] == 1
    assert payload["dataset_digest"] == manifest.digest
    assert payload["seed"] == 7
    assert payload["ratios"] == {"train": 0.6, "val": 0.2, "test": 0.2}

    crops = {crop.name: crop for crop in manifest.crops}
    for split, summary in payload["splits"].items():
        names = summary["images"]
        assert summary["panoramas"]
        assert summary["counts"] == {
            "images": len(names),
            "labels": len(names),
            "boxes": sum(len(crops[name].boxes) for name in names),
            "panoramas": len(summary["panoramas"]),
        }
        assert set(summary["class_distribution"]) == set(manifest.class_catalog)

    assert payload["counts"] == {
        "images": len(manifest.crops),
        "labels": len(manifest.crops),
        "boxes": sum(len(crop.boxes) for crop in manifest.crops),
        "panoramas": len(manifest.panoramas),
    }
    assert payload["class_distribution"] == manifest.class_distribution
    assert payload["digest"].startswith("sha256:")


def test_data_yaml_points_at_the_materialized_dataset(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)
    output = tmp_path / "dataset"

    result = split_dataset(manifest, export, output)

    document = (output / "data.yaml").read_text(encoding="utf-8")
    assert f'path: "{output.resolve().as_posix()}"' in document
    assert "train: images/train" in document
    assert "val: images/val" in document
    assert "test: images/test" in document
    for index, class_key in enumerate(manifest.class_catalog):
        assert f'  {index}: "{class_key}"' in document

    for split in ("train", "val", "test"):
        images = {
            path.stem for path in (output / "images" / split).iterdir()
        }
        labels = {
            path.stem for path in (output / "labels" / split).iterdir()
        }
        assert images == labels
        assert images == set(result.splits[split].images)


def test_a_non_empty_output_directory_needs_the_force_flag(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)
    output = tmp_path / "dataset"
    split_dataset(manifest, export, output)
    stale = output / "stale.txt"
    stale.write_text("from an earlier run", encoding="utf-8")
    before = (output / "split-manifest.json").read_bytes()

    with pytest.raises(SplitError, match="--force"):
        split_dataset(manifest, export, output)

    assert stale.exists()
    assert (output / "split-manifest.json").read_bytes() == before

    split_dataset(manifest, export, output, force=True)

    assert not stale.exists()
    assert (output / "split-manifest.json").is_file()


def test_an_output_that_would_replace_the_export_is_refused(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)

    with pytest.raises(SplitError, match="replace the export"):
        split_dataset(manifest, export, tmp_path)

    with pytest.raises(SplitError, match="inside the export"):
        split_dataset(manifest, export, export / "dataset")

    assert not (export / "dataset").exists()


def test_an_output_that_contains_the_working_directory_is_refused(
    tmp_path: Path,
) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)

    with pytest.raises(SplitError, match="working directory"):
        split_dataset(manifest, export, Path.cwd())


def test_an_export_with_too_few_panoramas_is_refused(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(
        tmp_path, {"stupa-induk": ("stupa-01", "stupa-02")}
    )

    with pytest.raises(SplitError, match="at least 3"):
        split_dataset(manifest, export, tmp_path / "dataset")

    assert not (tmp_path / "dataset").exists()


def test_a_panorama_without_crops_is_refused(tmp_path: Path) -> None:
    export, manifest, manifest_path = export_and_manifest(
        tmp_path,
        {
            "stupa-induk": ("stupa-01",),
            "pelataran-timur": ("pelataran-01",),
            "empty": (),
        },
    )

    assert read_manifest(manifest_path).to_dict() == manifest.to_dict()

    with pytest.raises(SplitError, match="declare no crops"):
        split_dataset(manifest, export, tmp_path / "dataset")


def test_ratios_that_do_not_add_up_are_refused(tmp_path: Path) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)

    with pytest.raises(SplitError, match="add up to 1.0"):
        split_dataset(manifest, export, tmp_path / "dataset", ratios=(0.8, 0.2, 0.2))

    with pytest.raises(SplitError, match="greater than zero"):
        split_dataset(manifest, export, tmp_path / "dataset", ratios=(0.8, 0.2, 0.0))


def test_a_manifest_that_no_longer_matches_the_export_is_refused(
    tmp_path: Path,
) -> None:
    export, manifest, _ = export_and_manifest(tmp_path)
    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.4 0.4 0.2 0.2\n", encoding="utf-8"
    )
    output = tmp_path / "dataset"

    with pytest.raises(SplitError, match="no longer matches"):
        split_dataset(manifest, export, output)

    assert not output.exists()


def test_a_written_manifest_reads_back_unchanged(tmp_path: Path) -> None:
    export, manifest, manifest_path = export_and_manifest(tmp_path)

    assert read_manifest(manifest_path).to_dict() == manifest.to_dict()


def test_an_unreadable_manifest_names_the_problem(tmp_path: Path) -> None:
    manifest_path = tmp_path / "dataset-manifest.json"
    manifest_path.write_text("{not json", encoding="utf-8")

    with pytest.raises(DatasetManifestError, match="not valid JSON"):
        read_manifest(manifest_path)


def run_split_cli(
    export: Path, manifest: Path, output: Path, *arguments: str
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            sys.executable,
            "-m",
            "dataset",
            "split",
            str(export),
            "--manifest",
            str(manifest),
            "--output",
            str(output),
            *arguments,
        ],
        cwd=SERVICES_ML,
        capture_output=True,
        text=True,
        env={**os.environ, "PYTHONPATH": str(SERVICES_ML)},
    )


def test_the_cli_splits_an_export_and_exits_zero(tmp_path: Path) -> None:
    export, _, manifest_path = export_and_manifest(tmp_path)
    output = tmp_path / "dataset"

    result = run_split_cli(
        export,
        manifest_path,
        output,
        "--seed",
        "5",
        "--ratios",
        "0.6",
        "0.2",
        "0.2",
    )

    assert result.returncode == 0, result.stderr
    assert "train=" in result.stdout
    payload = json.loads((output / "split-manifest.json").read_text(encoding="utf-8"))
    assert payload["seed"] == 5
    assert payload["ratios"] == {"train": 0.6, "val": 0.2, "test": 0.2}


def test_the_cli_refuses_a_manifest_that_no_longer_matches_the_export(
    tmp_path: Path,
) -> None:
    export, _, manifest_path = export_and_manifest(tmp_path)
    (export / "labels" / "stupa-01.txt").write_text(
        "0 0.4 0.4 0.2 0.2\n", encoding="utf-8"
    )
    output = tmp_path / "dataset"

    result = run_split_cli(export, manifest_path, output)

    assert result.returncode == 1
    assert "no longer matches" in result.stderr
    assert not output.exists()


def test_the_cli_reports_validation_issues_before_writing(tmp_path: Path) -> None:
    export, _, manifest_path = export_and_manifest(tmp_path)
    (export / "labels" / "stupa-01.txt").write_text("broken\n", encoding="utf-8")
    output = tmp_path / "dataset"

    result = run_split_cli(export, manifest_path, output)

    assert result.returncode == 1
    assert "label 'labels/stupa-01.txt' line 1" in result.stderr
    assert "expected 5 fields" in result.stderr
    assert not output.exists()


def test_the_cli_refuses_a_non_empty_output_without_force(tmp_path: Path) -> None:
    export, _, manifest_path = export_and_manifest(tmp_path)
    output = tmp_path / "dataset"
    assert run_split_cli(export, manifest_path, output).returncode == 0

    refused = run_split_cli(export, manifest_path, output)
    assert refused.returncode == 1
    assert "--force" in refused.stderr

    forced = run_split_cli(export, manifest_path, output, "--force")
    assert forced.returncode == 0, forced.stderr


def test_the_default_output_lives_in_the_ignored_datasets_area() -> None:
    assert default_output().parts[-2:] == ("datasets", "borobudur")
    assert default_output("other").parts[-2:] == ("datasets", "other")

    with pytest.raises(SplitError, match="single directory name"):
        default_output("../outside")


def test_a_shallow_layout_falls_back_to_the_package_parent(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    package = tmp_path / "app" / "dataset"
    monkeypatch.setattr(split_module, "__file__", str(package / "split.py"))

    assert split_module.default_output("borobudur") == (
        tmp_path / "app" / "datasets" / "borobudur"
    )
