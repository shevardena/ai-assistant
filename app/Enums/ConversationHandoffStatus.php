<?php

namespace App\Enums;

enum ConversationHandoffStatus: string
{
    case Ai = 'ai';
    case Requested = 'requested';
    case Human = 'human';
}
