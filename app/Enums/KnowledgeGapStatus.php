<?php

namespace App\Enums;

enum KnowledgeGapStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
