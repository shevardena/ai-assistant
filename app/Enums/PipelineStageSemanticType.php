<?php

namespace App\Enums;

enum PipelineStageSemanticType: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
}
