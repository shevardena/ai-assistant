<?php

namespace App\Enums;

enum WorkflowRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
