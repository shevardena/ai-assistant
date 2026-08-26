<?php

namespace App\Enums;

enum BotTestRunStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
}
