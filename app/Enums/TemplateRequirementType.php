<?php

namespace App\Enums;

enum TemplateRequirementType: string
{
    case Knowledge = 'knowledge';
    case Catalog = 'catalog';
    case LiveRead = 'live_read';
    case LiveWrite = 'live_write';
    case Workflow = 'workflow';
    case Channel = 'channel';
}
