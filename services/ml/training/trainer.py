"""Training adapters.

A trainer receives one request and writes the trained weights to `request.artifact`.
Importing this module never imports Ultralytics: its runtime is an opt-in install, and
orchestration tests substitute a trainer of their own.
"""

import shutil
from dataclasses import dataclass
from pathlib import Path
from typing import Protocol

from training.errors import TrainingError


@dataclass(frozen=True)
class TrainingRequest:
    """One run's inputs: the dataset, the base checkpoint, and where to write."""

    data_yaml: Path
    base_checkpoint: Path
    artifact: Path
    workspace: Path
    epochs: int
    image_size: int
    seed: int


class Trainer(Protocol):
    """Something that trains a detector from a request."""

    def train(self, request: TrainingRequest) -> None: ...


class UltralyticsTrainer:
    """Trains with Ultralytics, the only part of a run that needs its runtime installed."""

    def train(self, request: TrainingRequest) -> None:
        try:
            from ultralytics import YOLO
        except ImportError as error:
            raise TrainingError(
                "the ultralytics training runtime is not installed; install "
                "requirements-yolo.txt to train a detector"
            ) from error

        model = YOLO(str(request.base_checkpoint))
        project = request.workspace / "ultralytics"
        model.train(
            data=str(request.data_yaml),
            epochs=request.epochs,
            imgsz=request.image_size,
            seed=request.seed,
            deterministic=True,
            project=str(project),
            name="train",
            exist_ok=True,
            plots=False,
            verbose=False,
            workers=0,
        )

        best = Path(
            getattr(model.trainer, "best", None)
            or project / "train" / "weights" / "best.pt"
        )
        if not best.is_file():
            raise TrainingError(
                f"the trainer finished without writing a best checkpoint at '{best}'"
            )

        request.artifact.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(best, request.artifact)
