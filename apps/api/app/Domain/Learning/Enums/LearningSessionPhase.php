<?php

namespace App\Domain\Learning\Enums;

enum LearningSessionPhase: string
{
    case Pretest = 'pretest';
    case Exploration = 'exploration';
    case Posttest = 'posttest';
    case SelfEfficacy = 'self_efficacy';
    case Completed = 'completed';

    public function next(): ?self
    {
        return match ($this) {
            self::Pretest => self::Exploration,
            self::Exploration => self::Posttest,
            self::Posttest => self::SelfEfficacy,
            self::SelfEfficacy => self::Completed,
            self::Completed => null,
        };
    }
}
