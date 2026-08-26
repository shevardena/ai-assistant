<?php

use App\Http\Controllers\ActionHistoryController;
use App\Http\Controllers\AiPreviewController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiConnectionBuilderController;
use App\Http\Controllers\ApiImportController;
use App\Http\Controllers\ApiOperationController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BotCapabilityController;
use App\Http\Controllers\BotChannelController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\BotDatasetController;
use App\Http\Controllers\BotDesignController;
use App\Http\Controllers\BotDomainController;
use App\Http\Controllers\BotTestController;
use App\Http\Controllers\ConversationHandoffController;
use App\Http\Controllers\ConversationInboxController;
use App\Http\Controllers\ConversationOperationsController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataHealthController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DatasetFieldController;
use App\Http\Controllers\DatasetFieldMappingController;
use App\Http\Controllers\DatasetImportController;
use App\Http\Controllers\DatasetRecordController;
use App\Http\Controllers\DataSourceController;
use App\Http\Controllers\DataSourceCredentialController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\ImprovementCenterController;
use App\Http\Controllers\IntegrationHealthController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\KnowledgeGapController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PublicWidgetController;
use App\Http\Controllers\SourceFileController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\UnmappedDatasetFieldController;
use App\Http\Controllers\WidgetAssetController;
use App\Http\Controllers\WorkflowController;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::get('widget.js', WidgetAssetController::class)->name('widget.asset');
Route::get('widget/{botPublicId}', [PublicWidgetController::class, 'show'])->name('widget.show');
Route::post('stripe/webhook', StripeWebhookController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('stripe.webhook');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', SetUserLocale::class, EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('billing', [BillingController::class, 'index'])
            ->name('billing.index')
            ->middleware('team.permission:billing.view');
        Route::get('billing/success', [BillingController::class, 'success'])
            ->name('billing.success')
            ->middleware('team.permission:billing.view');
        Route::post('billing/checkout', [BillingController::class, 'checkout'])
            ->name('billing.checkout')
            ->middleware('team.permission:billing.manage');
        Route::post('billing/plan', [BillingController::class, 'updatePlan'])
            ->name('billing.plan.update')
            ->middleware('team.permission:billing.manage');
        Route::post('billing/portal', [BillingController::class, 'portal'])
            ->name('billing.portal')
            ->middleware('team.permission:billing.manage');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])
            ->name('billing.cancel')
            ->middleware('team.permission:billing.manage');
        Route::post('billing/resume', [BillingController::class, 'resume'])
            ->name('billing.resume')
            ->middleware('team.permission:billing.manage');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::patch('notifications/{notification}/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index')->middleware('team.permission:analytics.view');
        Route::get('improvements', [ImprovementCenterController::class, 'index'])->name('improvements.index')->middleware('team.permission:improvements.view');
        Route::get('actions', [ActionHistoryController::class, 'index'])->name('actions.index')->middleware('team.permission:actions.view');
        Route::get('actions/{actionReference}', [ActionHistoryController::class, 'show'])
            ->whereUuid('actionReference')
            ->name('actions.show')->middleware('team.permission:actions.view');
        Route::get('knowledge-gaps', [KnowledgeGapController::class, 'index'])->name('knowledge-gaps.index')->middleware('team.permission:knowledge_gaps.view');
        Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index')->middleware('team.permission:datasets.view');
        Route::patch('knowledge-gaps/{groupReference}', [KnowledgeGapController::class, 'update'])
            ->name('knowledge-gaps.update')->middleware('team.permission:knowledge_gaps.manage');
        Route::get('conversations', [ConversationInboxController::class, 'index'])->name('conversations.index')->middleware('team.permission:conversations.view');
        Route::get('conversations/{conversation:public_id}', [ConversationInboxController::class, 'show'])
            ->name('conversations.show')
            ->scopeBindings()
            ->middleware('team.permission:conversations.view');
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index')->middleware('team.permission:customers.view');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store')->middleware('team.permission:customers.manage');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show')->scopeBindings()->middleware('team.permission:customers.view');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::post('customers/{customer}/notes', [CustomerController::class, 'note'])->name('customers.notes.store')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::post('customers/{customer}/identities', [CustomerController::class, 'identity'])->name('customers.identities.store')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::patch('customers/{customer}/identities/{identity}/primary', [CustomerController::class, 'identityPrimary'])->name('customers.identities.primary')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::delete('customers/{customer}/identities/{identity}', [CustomerController::class, 'identityDestroy'])->name('customers.identities.destroy')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::post('customers/{customer}/facts', [CustomerController::class, 'fact'])->name('customers.facts.store')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::delete('customers/{customer}/facts/{fact}', [CustomerController::class, 'factDestroy'])->name('customers.facts.destroy')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::post('customers/{customer}/summary', [CustomerController::class, 'summary'])->name('customers.summary.generate')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::get('customers/{customer}/merge/{destination}', [CustomerController::class, 'mergePreview'])->name('customers.merge.preview')->middleware('team.permission:customers.manage');
        Route::post('customers/{customer}/merge', [CustomerController::class, 'merge'])->name('customers.merge')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::get('customer-fields', [CustomerController::class, 'fields'])->name('customer-fields.index')->middleware('team.permission:customers.manage');
        Route::post('customer-fields', [CustomerController::class, 'fieldStore'])->name('customer-fields.store')->middleware('team.permission:customers.manage');
        Route::patch('customer-fields/{field}', [CustomerController::class, 'fieldUpdate'])->name('customer-fields.update')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::patch('customer-fields/{field}/status', [CustomerController::class, 'fieldStatus'])->name('customer-fields.status')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::get('customer-segments', [CustomerController::class, 'segments'])->name('customer-segments.index')->middleware('team.permission:customers.view');
        Route::post('customer-segments', [CustomerController::class, 'segmentStore'])->name('customer-segments.store')->middleware('team.permission:customers.manage');
        Route::patch('customer-segments/{segment}', [CustomerController::class, 'segmentUpdate'])->name('customer-segments.update')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::delete('customer-segments/{segment}', [CustomerController::class, 'segmentDestroy'])->name('customer-segments.destroy')->scopeBindings()->middleware('team.permission:customers.manage');
        Route::post('customer-tags', [CustomerController::class, 'tag'])->name('customer-tags.store')->middleware('team.permission:customers.manage');
        Route::post('conversations/{conversation:public_id}/take-over', [ConversationHandoffController::class, 'takeOver'])
            ->name('conversations.handoff.take-over')
            ->scopeBindings()
            ->middleware('team.permission:conversations.handoff');
        Route::post('conversations/{conversation:public_id}/reply', [ConversationHandoffController::class, 'reply'])
            ->name('conversations.reply')
            ->scopeBindings()
            ->middleware('team.permission:conversations.reply');
        Route::post('conversations/{conversation:public_id}/return-to-ai', [ConversationHandoffController::class, 'returnToAi'])
            ->name('conversations.handoff.return-to-ai')
            ->scopeBindings()
            ->middleware('team.permission:conversations.handoff');
        Route::patch('conversations/{conversation:public_id}/status', [ConversationOperationsController::class, 'status'])
            ->name('conversations.status.update')
            ->scopeBindings()
            ->middleware('team.permission:conversations.manage');
        Route::patch('conversations/{conversation:public_id}/assignment', [ConversationOperationsController::class, 'assignment'])
            ->name('conversations.assignment.update')
            ->scopeBindings()
            ->middleware('team.permission:conversations.manage');
        Route::post('conversations/{conversation:public_id}/notes', [ConversationOperationsController::class, 'note'])
            ->name('conversations.notes.store')
            ->scopeBindings()
            ->middleware('team.permission:conversations.manage');
        Route::delete('conversations/{conversation:public_id}/notes/{note:public_id}', [ConversationOperationsController::class, 'deleteNote'])
            ->name('conversations.notes.destroy')
            ->scopeBindings()
            ->middleware('team.permission:conversations.manage');
        Route::post('conversation-tags', [ConversationOperationsController::class, 'createTag'])
            ->name('conversation-tags.store')
            ->middleware('team.permission:conversations.manage');
        Route::post('conversations/{conversation:public_id}/tags/{tag:public_id}', [ConversationOperationsController::class, 'attachTag'])
            ->name('conversations.tags.attach')
            ->middleware('team.permission:conversations.manage');
        Route::delete('conversations/{conversation:public_id}/tags/{tag:public_id}', [ConversationOperationsController::class, 'detachTag'])
            ->name('conversations.tags.detach')
            ->middleware('team.permission:conversations.manage');
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index')->middleware('team.permission:leads.view');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show')->scopeBindings()->middleware('team.permission:leads.view');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update')->scopeBindings()->middleware('team.permission:leads.update');
        Route::get('deals', [DealController::class, 'index'])->name('deals.index')->middleware('team.permission:deals.view');
        Route::get('deals/create', [DealController::class, 'create'])->name('deals.create')->middleware('team.permission:deals.manage');
        Route::post('deals', [DealController::class, 'store'])->name('deals.store')->middleware('team.permission:deals.manage');
        Route::get('deals/pipelines', [PipelineController::class, 'index'])->name('deals.pipelines')->middleware('team.permission:deals.view');
        Route::post('deals/pipelines', [PipelineController::class, 'store'])->name('pipelines.store')->middleware('team.permission:deals.manage');
        Route::patch('deals/pipelines/{pipeline}', [PipelineController::class, 'update'])->name('pipelines.update')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('deals/pipelines/{pipeline}/default', [PipelineController::class, 'default'])->name('pipelines.default')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::delete('deals/pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('deals/pipelines/{pipeline}/stages', [PipelineController::class, 'stageStore'])->name('pipeline-stages.store')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('deals/pipelines/{pipeline}/stages/reorder', [PipelineController::class, 'reorder'])->name('pipeline-stages.reorder')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::patch('deals/pipeline-stages/{stage}', [PipelineController::class, 'stageUpdate'])->name('pipeline-stages.update')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::delete('deals/pipeline-stages/{stage}', [PipelineController::class, 'stageDestroy'])->name('pipeline-stages.destroy')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::get('deals/{deal}', [DealController::class, 'show'])->name('deals.show')->scopeBindings()->middleware('team.permission:deals.view');
        Route::patch('deals/{deal}', [DealController::class, 'update'])->name('deals.update')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('deals/{deal}/stage', [DealController::class, 'stage'])->name('deals.stage')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('deals/{deal}/won', [DealController::class, 'won'])->name('deals.won')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('deals/{deal}/lost', [DealController::class, 'lost'])->name('deals.lost')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('deals/{deal}/reopen', [DealController::class, 'reopen'])->name('deals.reopen')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::post('leads/{lead}/deals', [DealController::class, 'createFromLead'])->name('leads.deals.store')->scopeBindings()->middleware('team.permission:deals.manage');
        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index')->middleware('team.permission:tasks.view');
        Route::get('tasks/create', [TaskController::class, 'create'])->name('tasks.create')->middleware('team.permission:tasks.manage');
        Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store')->middleware('team.permission:tasks.manage');
        Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show')->scopeBindings()->middleware('team.permission:tasks.view');
        Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update')->scopeBindings()->middleware('team.permission:tasks.manage');
        Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete')->scopeBindings()->middleware('team.permission:tasks.manage');
        Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen')->scopeBindings()->middleware('team.permission:tasks.manage');
        Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel')->scopeBindings()->middleware('team.permission:tasks.manage');
        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index')->middleware('team.permission:appointments.view');
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show')->scopeBindings()->middleware('team.permission:appointments.view');
        Route::patch('appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update')->scopeBindings()->middleware('team.permission:appointments.update');
        Route::get('support-tickets', [SupportTicketController::class, 'index'])->name('support-tickets.index')->middleware('team.permission:tickets.view');
        Route::get('support-tickets/{supportTicket}', [SupportTicketController::class, 'show'])->name('support-tickets.show')->scopeBindings()->middleware('team.permission:tickets.view');
        Route::patch('support-tickets/{supportTicket}', [SupportTicketController::class, 'update'])->name('support-tickets.update')->scopeBindings()->middleware('team.permission:tickets.update');
        Route::get('integrations/health', [IntegrationHealthController::class, 'index'])->name('integration-health.index')->middleware('team.permission:integration_health.view');
        Route::get('integrations/health/{dataSource}', [IntegrationHealthController::class, 'show'])->name('integration-health.show')->middleware('team.permission:integration_health.view');
        Route::get('data-health', [DataHealthController::class, 'index'])->name('data-health.index')->middleware('team.permission:data_health.view');
        Route::get('data-health/{dataset}', [DataHealthController::class, 'show'])->name('data-health.show')->middleware('team.permission:data_health.view');
        Route::get('onboarding', [OnboardingController::class, 'index'])
            ->name('onboarding.index')
            ->middleware('team.permission:bots.view');
        Route::get('onboarding/templates/{template}', [OnboardingController::class, 'template'])
            ->name('onboarding.template')
            ->middleware('team.permission:bots.view');
        Route::post('onboarding/apply', [OnboardingController::class, 'apply'])
            ->name('onboarding.apply')
            ->middleware('team.permission:bots.update');
        Route::resource('bots', BotController::class);
        Route::get('bots/{bot}/channels', [BotChannelController::class, 'index'])
            ->name('bots.channels.index')
            ->scopeBindings()
            ->middleware('team.permission:channels.view');
        Route::post('bots/{bot}/channels/whatsapp', [BotChannelController::class, 'configureWhatsApp'])
            ->name('bots.channels.whatsapp.store')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::delete('bots/{bot}/channels/whatsapp', [BotChannelController::class, 'disconnectWhatsApp'])
            ->name('bots.channels.whatsapp.destroy')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::post('bots/{bot}/channels/instagram', [BotChannelController::class, 'configureInstagram'])
            ->name('bots.channels.instagram.store')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::delete('bots/{bot}/channels/instagram', [BotChannelController::class, 'disconnectInstagram'])
            ->name('bots.channels.instagram.destroy')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::post('bots/{bot}/channels/messenger', [BotChannelController::class, 'configureMessenger'])
            ->name('bots.channels.messenger.store')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::delete('bots/{bot}/channels/messenger', [BotChannelController::class, 'disconnectMessenger'])
            ->name('bots.channels.messenger.destroy')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::post('bots/{bot}/channels/telegram', [BotChannelController::class, 'configureTelegram'])
            ->name('bots.channels.telegram.store')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::delete('bots/{bot}/channels/telegram', [BotChannelController::class, 'disconnectTelegram'])
            ->name('bots.channels.telegram.destroy')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::post('bots/{bot}/channels/sms', [BotChannelController::class, 'configureSms'])
            ->name('bots.channels.sms.store')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::delete('bots/{bot}/channels/sms', [BotChannelController::class, 'disconnectSms'])
            ->name('bots.channels.sms.destroy')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::post('bots/{bot}/channels/email', [BotChannelController::class, 'configureEmail'])
            ->name('bots.channels.email.store')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::delete('bots/{bot}/channels/email', [BotChannelController::class, 'disconnectEmail'])
            ->name('bots.channels.email.destroy')
            ->scopeBindings()
            ->middleware('team.permission:channels.manage');
        Route::get('bots/{bot}/setup', [OnboardingController::class, 'setup'])
            ->name('bots.setup.show')
            ->scopeBindings()
            ->middleware('team.permission:bots.view');
        Route::get('bots/{bot}/capabilities', [BotCapabilityController::class, 'show'])
            ->name('bots.capabilities.show');
        Route::resource('bots.tests', BotTestController::class)
            ->parameters(['tests' => 'testScenario'])
            ->except(['index', 'show'])
            ->middleware('team.permission:bot_tests.manage');
        Route::get('bots/{bot}/tests', [BotTestController::class, 'index'])->name('bots.tests.index')->middleware('team.permission:bot_tests.view');
        Route::get('bots/{bot}/tests/{testScenario:public_id}', [BotTestController::class, 'show'])
            ->name('bots.tests.show')
            ->scopeBindings()
            ->middleware('team.permission:bot_tests.view');
        Route::post('bots/{bot}/tests/{testScenario:public_id}/run', [BotTestController::class, 'run'])
            ->name('bots.tests.run')
            ->scopeBindings()
            ->middleware('team.permission:bot_tests.manage');
        Route::get('bots/{bot}/design', [BotDesignController::class, 'edit'])->name('bots.design.edit');
        Route::patch('bots/{bot}/design', [BotDesignController::class, 'update'])->name('bots.design.update');
        Route::put('bots/{bot}/datasets', [BotDatasetController::class, 'update'])
            ->name('bots.datasets.update');
        Route::post('bots/{bot}/ai/test', AiPreviewController::class)->name('bots.ai.test');
        Route::post('bots/{bot}/ai/reset', [AiPreviewController::class, 'reset'])->name('bots.ai.reset');
        Route::post('bots/{bot}/ai/actions/{actionReference}/confirm', [AiPreviewController::class, 'confirm'])
            ->name('bots.ai.actions.confirm');
        Route::post('bots/{bot}/ai/actions/{actionReference}/cancel', [AiPreviewController::class, 'cancel'])
            ->name('bots.ai.actions.cancel');
        Route::post('bots/{bot}/ai/forms/{formReference}/submit', [AiPreviewController::class, 'submitForm'])
            ->name('bots.ai.forms.submit');
        Route::post('bots/{bot}/ai/appointments/{appointmentReference}/select', [AiPreviewController::class, 'selectAppointment'])
            ->name('bots.ai.appointments.select');
        Route::post('bots/{bot}/domains', [BotDomainController::class, 'store'])->name('bots.domains.store');
        Route::delete('bots/{bot}/domains/{domain}', [BotDomainController::class, 'destroy'])->name('bots.domains.destroy');
        Route::get('data-sources/api/create', [ApiConnectionBuilderController::class, 'create'])
            ->name('data-sources.api.create')
            ->middleware('team.permission:data_sources.manage');
        Route::post('data-sources/api/test', [ApiConnectionBuilderController::class, 'test'])
            ->name('data-sources.api.test')
            ->middleware('team.permission:data_sources.manage')
            ->middleware('throttle:10,1');
        Route::post('data-sources/api', [ApiConnectionBuilderController::class, 'store'])
            ->name('data-sources.api.store')
            ->middleware('team.permission:data_sources.manage');
        Route::get('data-sources/graphql/create', [ApiConnectionBuilderController::class, 'createGraphql'])
            ->name('data-sources.graphql.create')
            ->middleware('team.permission:data_sources.manage');
        Route::post('data-sources/graphql', [ApiConnectionBuilderController::class, 'storeGraphql'])
            ->name('data-sources.graphql.store')
            ->middleware('team.permission:data_sources.manage');
        Route::get('data-sources/{data_source}/graphql-builder', [ApiConnectionBuilderController::class, 'editGraphql'])
            ->name('data-sources.graphql.edit')
            ->scopeBindings()
            ->middleware('team.permission:data_sources.manage');
        Route::match(['put', 'patch'], 'data-sources/{data_source}/graphql-builder', [ApiConnectionBuilderController::class, 'updateGraphql'])
            ->name('data-sources.graphql.update')
            ->scopeBindings()
            ->middleware('team.permission:data_sources.manage');
        Route::get('data-sources/{data_source}/api-builder', [ApiConnectionBuilderController::class, 'edit'])
            ->name('data-sources.api.edit')
            ->scopeBindings()
            ->middleware('team.permission:data_sources.manage');
        Route::match(['put', 'patch'], 'data-sources/{data_source}/api-builder', [ApiConnectionBuilderController::class, 'update'])
            ->name('data-sources.api.update')
            ->scopeBindings()
            ->middleware('team.permission:data_sources.manage');
        Route::get('data-sources/create/file', [DataSourceController::class, 'createFile'])
            ->name('data-sources.create.file');
        Route::resource('data-sources', DataSourceController::class);
        Route::resource('datasets', DatasetController::class);
        Route::get('datasets/{dataset}/records', [DatasetRecordController::class, 'index'])
            ->name('datasets.records.index')
            ->scopeBindings();
        Route::get('datasets/{dataset}/records/create', [DatasetRecordController::class, 'create'])
            ->name('datasets.records.create')
            ->scopeBindings();
        Route::post('datasets/{dataset}/records', [DatasetRecordController::class, 'store'])
            ->name('datasets.records.store')
            ->scopeBindings();
        Route::get('datasets/{dataset}/records/{record}', [DatasetRecordController::class, 'show'])
            ->name('datasets.records.show')
            ->scopeBindings();
        Route::get('datasets/{dataset}/records/{record}/edit', [DatasetRecordController::class, 'edit'])
            ->name('datasets.records.edit')
            ->scopeBindings();
        Route::match(['put', 'patch'], 'datasets/{dataset}/records/{record}', [DatasetRecordController::class, 'update'])
            ->name('datasets.records.update')
            ->scopeBindings();
        Route::patch('datasets/{dataset}/records/{record}/deactivate', [DatasetRecordController::class, 'deactivate'])
            ->name('datasets.records.deactivate')
            ->scopeBindings();
        Route::patch('datasets/{dataset}/records/{record}/activate', [DatasetRecordController::class, 'activate'])
            ->name('datasets.records.activate')
            ->scopeBindings();
        Route::delete('datasets/{dataset}/records/{record}', [DatasetRecordController::class, 'destroy'])
            ->name('datasets.records.destroy')
            ->scopeBindings();
        Route::post('datasets/{dataset}/imports', [DatasetImportController::class, 'store'])
            ->name('datasets.imports.store');
        Route::post('datasets/{dataset}/api-imports', [ApiImportController::class, 'store'])
            ->name('datasets.api-imports.store');
        Route::resource('data-sources.credentials', DataSourceCredentialController::class)
            ->only(['index', 'store', 'destroy'])
            ->middleware('team.permission:credentials.manage');
        Route::get('data-sources/{data_source}/api-operations/create', [ApiOperationController::class, 'create'])
            ->name('data-sources.api-operations.create')
            ->scopeBindings()
            ->middleware('team.permission:api_operations.manage');
        Route::post('data-sources/{data_source}/api-operations/test', [ApiOperationController::class, 'test'])
            ->name('data-sources.api-operations.test')
            ->scopeBindings()
            ->middleware('team.permission:api_operations.manage')
            ->middleware('throttle:10,1');
        Route::put('data-sources/{data_source}/api-operations/{api_operation}/sync-schedule', [ApiOperationController::class, 'updateSyncSchedule'])
            ->name('data-sources.api-operations.sync-schedule.update')
            ->scopeBindings()
            ->middleware('team.permission:api_operations.manage');
        Route::post('data-sources/{data_source}/api-operations/{api_operation}/sync', [ApiOperationController::class, 'runSync'])
            ->name('data-sources.api-operations.sync')
            ->scopeBindings()
            ->middleware('team.permission:api_operations.manage');
        Route::post('data-sources/{data_source}/api-operations/{api_operation}/sync/pause', [ApiOperationController::class, 'pauseSync'])
            ->name('data-sources.api-operations.sync.pause')
            ->scopeBindings()
            ->middleware('team.permission:api_operations.manage');
        Route::post('data-sources/{data_source}/api-operations/{api_operation}/sync/resume', [ApiOperationController::class, 'resumeSync'])
            ->name('data-sources.api-operations.sync.resume')
            ->scopeBindings()
            ->middleware('team.permission:api_operations.manage');
        Route::resource('data-sources.api-operations', ApiOperationController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->middleware('team.permission:api_operations.manage');
        Route::resource('datasets.fields', DatasetFieldController::class)
            ->except(['show', 'index']);
        Route::get('datasets/{dataset}/fields/unmapped', [UnmappedDatasetFieldController::class, 'index'])
            ->name('datasets.fields.unmapped.index');
        Route::post('datasets/{dataset}/fields/unmapped', [UnmappedDatasetFieldController::class, 'store'])
            ->name('datasets.fields.unmapped.store');
        Route::post('datasets/{dataset}/field-discovery', [DatasetFieldMappingController::class, 'discover'])
            ->name('datasets.fields.discovery');
        Route::put('datasets/{dataset}/fields', [DatasetFieldMappingController::class, 'update'])
            ->name('datasets.fields.bulk-update');
        Route::resource('data-sources.files', SourceFileController::class)
            ->only(['store', 'destroy']);
        Route::resource('workflows', WorkflowController::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
            ->scoped()
            ->middleware('team.permission:workflows.view');
        Route::patch('workflows/{workflow}/activate', [WorkflowController::class, 'activate'])
            ->name('workflows.activate')
            ->middleware('team.permission:workflows.manage');
        Route::patch('workflows/{workflow}/disable', [WorkflowController::class, 'disable'])
            ->name('workflows.disable')
            ->middleware('team.permission:workflows.manage');
    });

Route::middleware(['auth', SetUserLocale::class])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
