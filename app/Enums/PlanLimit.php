<?php

namespace App\Enums;

enum PlanLimit: string
{
    case Bots = 'bots';
    case TeamMembers = 'team_members';
    case MonthlyConversations = 'monthly_conversations';
    case MonthlyActions = 'monthly_actions';

    public function label(): string
    {
        return match ($this) {
            self::Bots => 'Bots',
            self::TeamMembers => 'Team members',
            self::MonthlyConversations => 'Customer conversations',
            self::MonthlyActions => 'Production actions',
        };
    }
}
