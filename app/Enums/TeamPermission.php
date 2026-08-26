<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case AnalyticsView = 'analytics.view';

    case ConversationsView = 'conversations.view';
    case ConversationsReply = 'conversations.reply';
    case ConversationsHandoff = 'conversations.handoff';
    case ConversationsManage = 'conversations.manage';

    case ChannelsView = 'channels.view';
    case ChannelsManage = 'channels.manage';

    case LeadsView = 'leads.view';
    case LeadsUpdate = 'leads.update';

    case AppointmentsView = 'appointments.view';
    case AppointmentsUpdate = 'appointments.update';

    case TicketsView = 'tickets.view';
    case TicketsUpdate = 'tickets.update';

    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';

    case DealsView = 'deals.view';
    case DealsManage = 'deals.manage';

    case TasksView = 'tasks.view';
    case TasksManage = 'tasks.manage';

    case BotsView = 'bots.view';
    case BotsUpdate = 'bots.update';
    case BotsContentEdit = 'bots.content.edit';

    case DatasetsView = 'datasets.view';
    case DatasetsManage = 'datasets.manage';
    case DatasetFieldsManage = 'dataset_fields.manage';

    case DataSourcesView = 'data_sources.view';
    case DataSourcesManage = 'data_sources.manage';
    case CredentialsManage = 'credentials.manage';
    case ApiOperationsManage = 'api_operations.manage';

    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';

    case ActionsView = 'actions.view';
    case DataHealthView = 'data_health.view';
    case IntegrationHealthView = 'integration_health.view';
    case ImprovementsView = 'improvements.view';
    case KnowledgeGapsView = 'knowledge_gaps.view';
    case KnowledgeGapsManage = 'knowledge_gaps.manage';

    case BotTestsView = 'bot_tests.view';
    case BotTestsManage = 'bot_tests.manage';

    case TeamMembersView = 'team_members.view';
    case TeamMembersManage = 'team_members.manage';

    case WidgetManage = 'widget.manage';

    case WorkflowsView = 'workflows.view';
    case WorkflowsManage = 'workflows.manage';

    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';
}
