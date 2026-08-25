<?php

namespace App\Domain\Research\Enums;

enum AssessmentType: string
{
    case Pretest = 'pretest';
    case Posttest = 'posttest';
    case SelfEfficacy = 'self_efficacy';
}
