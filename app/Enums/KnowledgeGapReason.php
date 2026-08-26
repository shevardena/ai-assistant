<?php

namespace App\Enums;

enum KnowledgeGapReason: string
{
    case NoKnowledgeMatch = 'no_knowledge_match';
    case NoResults = 'no_results';
}
