<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case SupportAgent = 'support_agent';
    case ContentManager = 'content_manager';
    case Analyst = 'analyst';
    case Developer = 'developer';

    /**
     * Legacy broad-access membership retained for existing teams and invitations.
     */
    case Member = 'member';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::SupportAgent => 'Support Agent',
            self::ContentManager => 'Content Manager',
            self::Analyst => 'Analyst',
            self::Developer => 'Developer',
            self::Member => 'Member',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full Team access and ownership controls.',
            self::Admin => 'Broad Team administration and operational access.',
            self::SupportAgent => 'Handles conversations, handoffs, appointments, and support tickets.',
            self::ContentManager => 'Maintains Bot content, datasets, and knowledge.',
            self::Analyst => 'Read-only access to analytics and operational reporting.',
            self::Developer => 'Manages Bot configuration, APIs, integrations, and tests.',
            self::Member => 'Legacy Team member access retained for compatibility.',
        };
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => array_values(array_filter(
                TeamPermission::cases(),
                fn (TeamPermission $permission): bool => ! in_array($permission, [
                    TeamPermission::DeleteTeam,
                    TeamPermission::BillingManage,
                ], true),
            )),
            self::SupportAgent => [
                TeamPermission::ConversationsView,
                TeamPermission::ConversationsReply,
                TeamPermission::ConversationsHandoff,
                TeamPermission::ConversationsManage,
                TeamPermission::LeadsView,
                TeamPermission::AppointmentsView,
                TeamPermission::AppointmentsUpdate,
                TeamPermission::TicketsView,
                TeamPermission::TicketsUpdate,
                TeamPermission::CustomersView,
                TeamPermission::CustomersManage,
                TeamPermission::DealsView,
                TeamPermission::DealsManage,
                TeamPermission::TasksView,
                TeamPermission::TasksManage,
            ],
            self::ContentManager => [
                TeamPermission::ChannelsView,
                TeamPermission::BotsView,
                TeamPermission::BotsContentEdit,
                TeamPermission::DatasetsView,
                TeamPermission::DatasetsManage,
                TeamPermission::DatasetFieldsManage,
                TeamPermission::DataSourcesView,
                TeamPermission::DataSourcesManage,
                TeamPermission::DataHealthView,
                TeamPermission::KnowledgeGapsView,
                TeamPermission::KnowledgeGapsManage,
                TeamPermission::ImprovementsView,
                TeamPermission::WorkflowsView,
            ],
            self::Analyst => [
                TeamPermission::ChannelsView,
                TeamPermission::AnalyticsView,
                TeamPermission::ConversationsView,
                TeamPermission::ActionsView,
                TeamPermission::DataHealthView,
                TeamPermission::IntegrationHealthView,
                TeamPermission::ImprovementsView,
                TeamPermission::KnowledgeGapsView,
                TeamPermission::LeadsView,
                TeamPermission::AppointmentsView,
                TeamPermission::TicketsView,
                TeamPermission::CustomersView,
                TeamPermission::DealsView,
                TeamPermission::TasksView,
                TeamPermission::BotTestsView,
                TeamPermission::WorkflowsView,
            ],
            self::Developer => [
                TeamPermission::ChannelsView,
                TeamPermission::ChannelsManage,
                TeamPermission::BotsView,
                TeamPermission::BotsUpdate,
                TeamPermission::DataSourcesView,
                TeamPermission::DataSourcesManage,
                TeamPermission::CredentialsManage,
                TeamPermission::ApiOperationsManage,
                TeamPermission::IntegrationsView,
                TeamPermission::IntegrationsManage,
                TeamPermission::IntegrationHealthView,
                TeamPermission::DataHealthView,
                TeamPermission::ActionsView,
                TeamPermission::BotTestsView,
                TeamPermission::BotTestsManage,
                TeamPermission::WidgetManage,
                TeamPermission::WorkflowsView,
                TeamPermission::WorkflowsManage,
            ],
            /**
             * Existing installations used `member` for tenant-owned CRUD. Keep that access
             * while intentionally excluding Team administration and sensitive integrations.
             */
            self::Member => [
                TeamPermission::ChannelsView,
                TeamPermission::AnalyticsView,
                TeamPermission::ConversationsView,
                TeamPermission::ConversationsReply,
                TeamPermission::ConversationsHandoff,
                TeamPermission::ConversationsManage,
                TeamPermission::LeadsView,
                TeamPermission::LeadsUpdate,
                TeamPermission::AppointmentsView,
                TeamPermission::AppointmentsUpdate,
                TeamPermission::TicketsView,
                TeamPermission::TicketsUpdate,
                TeamPermission::BotsView,
                TeamPermission::BotsUpdate,
                TeamPermission::BotsContentEdit,
                TeamPermission::DatasetsView,
                TeamPermission::DatasetsManage,
                TeamPermission::DatasetFieldsManage,
                TeamPermission::DataSourcesView,
                TeamPermission::DataSourcesManage,
                TeamPermission::ActionsView,
                TeamPermission::DataHealthView,
                TeamPermission::IntegrationHealthView,
                TeamPermission::ImprovementsView,
                TeamPermission::KnowledgeGapsView,
                TeamPermission::KnowledgeGapsManage,
                TeamPermission::BotTestsView,
                TeamPermission::BotTestsManage,
                TeamPermission::WorkflowsView,
                TeamPermission::DealsView,
                TeamPermission::DealsManage,
                TeamPermission::TasksView,
                TeamPermission::TasksManage,
            ],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 6,
            self::Admin => 5,
            self::Developer, self::ContentManager => 4,
            self::SupportAgent, self::Analyst, self::Member => 2,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to team members (excludes Owner).
     *
     * @return array<array{value: string, label: string, description: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->filter(fn (self $role) => $role !== self::Owner)
            ->map(fn (self $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ])
            ->values()
            ->toArray();
    }
}
