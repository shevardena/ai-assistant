<?php

namespace App\Enums;

enum WorkflowActionRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
