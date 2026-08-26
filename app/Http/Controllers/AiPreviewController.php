<?php

namespace App\Http\Controllers;

use App\Enums\RuntimeMode;
use App\Http\Requests\ConfirmAiActionRequest;
use App\Http\Requests\StoreAiAppointmentSelectionRequest;
use App\Http\Requests\StoreAiFormSubmissionRequest;
use App\Http\Requests\StoreAiPreviewRequest;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Team;
use App\Services\Ai\AiException;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Conversations\ActionConfirmationService;
use App\Services\Conversations\ConversationAppointmentService;
use App\Services\Conversations\ConversationReply;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiPreviewController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ActionConfirmationService $actionConfirmationService,
        private readonly ConversationAppointmentService $appointmentService,
    ) {}

    public function __invoke(StoreAiPreviewRequest $request, Team $currentTeam, Bot $bot): JsonResponse
    {
        Gate::authorize('view', $bot);

        try {
            $conversation = $this->conversation($bot, $request->validated('conversation_id'));
            $reply = $this->conversationService->sendMessage(
                $bot,
                $conversation,
                (string) $request->validated('message'),
                RuntimeMode::Preview,
            );
        } catch (AiException $exception) {
            logger()->warning('AI preview request failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'exception' => $exception::class,
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'conversation_id' => $reply->conversation->public_id,
            'answer' => $reply->aiResponse->answer,
            'tool_calls_count' => $reply->aiResponse->toolCallsCount,
            'searches' => $reply->aiResponse->searches,
            'blocks' => $reply->blocks,
            'cards' => $reply->cards,
            'usage' => $reply->aiResponse->usage,
        ]);
    }

    public function reset(Team $currentTeam, Bot $bot): JsonResponse
    {
        Gate::authorize('view', $bot);

        return response()->json([
            'conversation_id' => $this->conversationService->createPreviewConversation($bot)->public_id,
        ]);
    }

    public function submitForm(
        StoreAiFormSubmissionRequest $request,
        Team $currentTeam,
        Bot $bot,
        string $formReference,
    ): JsonResponse {
        Gate::authorize('view', $bot);

        try {
            $conversation = $this->conversation($bot, $request->validated('conversation_id'));
            $reply = $this->conversationService->submitForm(
                $bot,
                $conversation,
                $formReference,
                (array) $request->validated('values'),
                mode: RuntimeMode::Preview,
            );
        } catch (AiException $exception) {
            logger()->warning('AI preview form submission failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'exception' => $exception::class,
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->formResponse($reply));
    }

    public function selectAppointment(
        StoreAiAppointmentSelectionRequest $request,
        Team $currentTeam,
        Bot $bot,
        string $appointmentReference,
    ): JsonResponse {
        Gate::authorize('view', $bot);

        $conversation = $this->conversation($bot, $request->validated('conversation_id'));

        try {
            $reply = $this->conversationService->submitAppointmentSlot(
                $bot,
                $conversation,
                $appointmentReference,
                (string) $request->validated('slot_reference'),
                mode: RuntimeMode::Preview,
            );
        } catch (HttpException $exception) {
            $block = $exception->getStatusCode() === 409
                ? $this->appointmentService->blockForReference($conversation, $appointmentReference)?->toArray()
                : null;

            return response()->json([
                'message' => $exception->getMessage(),
                'appointment_block' => $block,
            ], $exception->getStatusCode());
        }

        return response()->json($this->appointmentResponse($reply));
    }

    public function confirm(
        ConfirmAiActionRequest $request,
        Team $currentTeam,
        Bot $bot,
        string $actionReference,
    ): JsonResponse {
        Gate::authorize('view', $bot);

        $conversation = $this->conversation($bot, (string) $request->validated('conversation_id'));
        $result = $this->actionConfirmationService->confirm(
            $bot,
            ToolExecutionContext::forBot($bot, $conversation, mode: RuntimeMode::Preview),
            $actionReference,
        );

        return $this->actionResponse($result);
    }

    public function cancel(
        ConfirmAiActionRequest $request,
        Team $currentTeam,
        Bot $bot,
        string $actionReference,
    ): JsonResponse {
        Gate::authorize('view', $bot);

        $conversation = $this->conversation($bot, (string) $request->validated('conversation_id'));
        $result = $this->actionConfirmationService->cancel(
            $bot,
            ToolExecutionContext::forBot($bot, $conversation, mode: RuntimeMode::Preview),
            $actionReference,
        );

        return $this->actionResponse($result);
    }

    private function conversation(Bot $bot, ?string $publicId): Conversation
    {
        if ($publicId === null) {
            return $this->conversationService->createPreviewConversation($bot);
        }

        return $bot->conversations()
            ->whereNull('visitor_id')
            ->where('public_id', $publicId)
            ->whereJsonContains('metadata->source', 'dashboard_preview')
            ->firstOrFail();
    }

    private function actionResponse(ToolResult $result): JsonResponse
    {
        $block = $result->blocks[0] ?? null;

        if (! is_array($block)) {
            return response()->json([
                'message' => 'This action is not available in the current conversation.',
            ], 404);
        }

        return response()->json([
            'status' => data_get($block, 'data.status'),
            'block' => $block,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formResponse(ConversationReply $reply): array
    {
        return [
            'conversation_id' => $reply->conversation->public_id,
            'form_block' => $reply->formBlock,
            'user_message' => [
                'role' => 'user',
                'content' => $reply->userMessage->content,
                'blocks' => [],
            ],
            'message' => [
                'role' => 'assistant',
                'content' => $reply->assistantMessage->content,
                'blocks' => $reply->blocks,
                'cards' => $reply->cards,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentResponse(ConversationReply $reply): array
    {
        return [
            'conversation_id' => $reply->conversation->public_id,
            'appointment_block' => $reply->appointmentBlock,
            'user_message' => [
                'role' => 'user',
                'content' => $reply->userMessage->content,
                'blocks' => [],
            ],
            'message' => [
                'role' => 'assistant',
                'content' => $reply->assistantMessage->content,
                'blocks' => $reply->blocks,
                'cards' => $reply->cards,
            ],
        ];
    }
}
