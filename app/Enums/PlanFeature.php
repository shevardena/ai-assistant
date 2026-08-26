<?php

namespace App\Enums;

enum PlanFeature: string
{
    case Analytics = 'analytics';
    case HumanHandoff = 'human_handoff';
    case Workflows = 'workflows';
    case BotTesting = 'bot_testing';
    case AdvancedHealth = 'advanced_health';
    case BusinessTemplates = 'business_templates';
    case Notifications = 'notifications';
    case VoiceInput = 'voice_input';

    public function label(): string
    {
        return match ($this) {
            self::Analytics => 'Analytics',
            self::HumanHandoff => 'Human handoff',
            self::Workflows => 'Workflows',
            self::BotTesting => 'Bot Testing',
            self::AdvancedHealth => 'Advanced health',
            self::BusinessTemplates => 'Business templates',
            self::Notifications => 'Notifications',
            self::VoiceInput => 'Voice input (speech-to-text)',
        };
    }
}
