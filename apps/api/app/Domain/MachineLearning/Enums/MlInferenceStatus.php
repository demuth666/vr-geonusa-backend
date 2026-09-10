<?php

namespace App\Domain\MachineLearning\Enums;

enum MlInferenceStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
