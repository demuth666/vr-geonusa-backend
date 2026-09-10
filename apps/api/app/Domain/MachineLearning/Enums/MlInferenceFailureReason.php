<?php

namespace App\Domain\MachineLearning\Enums;

enum MlInferenceFailureReason: string
{
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case UpstreamError = 'upstream_error';
    case InvalidResponse = 'invalid_response';
}
