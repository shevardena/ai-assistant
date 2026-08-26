<?php

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WidgetVisitor;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Conversations\Blocks\FormBlockStatus;
use App\Services\Conversations\ConversationFormService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @return array{0: Bot, 1: Conversation, 2: string, 3: WidgetVisitor|null}
 */
function formSubmissionContext(bool $withVisitor = false): array
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['team_id' => $user->currentTeam->id]);
    $visitor = $withVisitor
        ? WidgetVisitor::factory()->create(['bot_id' => $bot->id])
        : null;
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor?->id,
    ]);
    $result = app(ConversationFormService::class)->request(
        ToolExecutionContext::forBot($bot, $conversation, visitor: $visitor),
        'capture_lead',
        [
            'title' => 'Contact details',
            'fields' => [
                [
                    'name' => 'email',
                    'label' => 'Email',
                    'type' => 'email',
                    'required' => true,
                ],
                [
                    'name' => 'reason',
                    'label' => 'Reason',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => 'sales', 'label' => 'Sales'],
                        ['value' => 'support', 'label' => 'Support'],
                    ],
                ],
            ],
        ],
    );

    return [
        $bot,
        $conversation,
        (string) data_get($result->blocks, '0.data.form_reference'),
        $visitor,
    ];
}

test('trusted forms submit once and persist concise state', function () {
    [$bot, $conversation, $reference] = formSubmissionContext();

    $submission = app(ConversationFormService::class)->submit(
        $bot,
        $conversation,
        $reference,
        [
            'email' => 'customer@example.test',
            'reason' => 'support',
        ],
    );

    $memory = $conversation->state()->firstOrFail()->memory;

    expect($submission->block->status)->toBe(FormBlockStatus::Submitted)
        ->and($submission->values)->toBe([
            'email' => 'customer@example.test',
            'reason' => 'support',
        ])
        ->and($memory['active_form']['status'])->toBe(FormBlockStatus::Submitted->value)
        ->and($memory['forms'][$reference]['status'])->toBe(FormBlockStatus::Submitted->value);

    expect(fn () => app(ConversationFormService::class)->submit(
        $bot,
        $conversation,
        $reference,
        ['email' => 'customer@example.test', 'reason' => 'support'],
    ))->toThrow(HttpException::class);
});

test('form submission rejects unknown fields and invalid select options', function () {
    [$bot, $conversation, $reference] = formSubmissionContext();

    expect(fn () => app(ConversationFormService::class)->submit(
        $bot,
        $conversation,
        $reference,
        [
            'email' => 'customer@example.test',
            'reason' => 'support',
            'team_id' => 'foreign',
        ],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(ConversationFormService::class)->submit(
        $bot,
        $conversation,
        $reference,
        [
            'email' => 'customer@example.test',
            'reason' => 'not-allowed',
        ],
    ))->toThrow(ValidationException::class);
});

test('form submission rejects another conversation or bot', function () {
    [$bot, $conversation, $reference] = formSubmissionContext();
    $otherConversation = Conversation::factory()->create(['bot_id' => $bot->id]);
    $otherBot = Bot::factory()->create(['team_id' => $bot->team_id]);

    $submit = fn (Bot $targetBot, Conversation $targetConversation) => app(ConversationFormService::class)->submit(
        $targetBot,
        $targetConversation,
        $reference,
        ['email' => 'customer@example.test', 'reason' => 'support'],
    );

    expect(fn () => $submit($bot, $otherConversation))->toThrow(HttpException::class)
        ->and(fn () => $submit($otherBot, $conversation))->toThrow(HttpException::class);
});

test('widget form submission rejects another visitor', function () {
    [$bot, $conversation, $reference] = formSubmissionContext(true);
    $otherVisitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);

    expect(fn () => app(ConversationFormService::class)->submit(
        $bot,
        $conversation,
        $reference,
        ['email' => 'customer@example.test', 'reason' => 'support'],
        $otherVisitor,
    ))->toThrow(HttpException::class);
});
