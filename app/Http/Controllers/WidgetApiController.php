<?php

namespace App\Http\Controllers;

use App\Data\ChannelInboundMessage;
use App\Enums\PlanFeature;
use App\Exceptions\PlanLimitExceededException;
use App\Http\Requests\WidgetActionRequest;
use App\Http\Requests\WidgetAppointmentSelectionRequest;
use App\Http\Requests\WidgetFormSubmissionRequest;
use App\Http\Requests\WidgetMessageRequest;
use App\Http\Requests\WidgetMessagesRequest;
use App\Http\Requests\WidgetSessionRequest;
use App\Http\Requests\WidgetTranscriptionRequest;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WidgetVisitor;
use App\Services\Ai\AiException;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Cards\BotWidgetAppearance;
use App\Services\Channels\ChannelResponseFormatter;
use App\Services\Conversations\ActionConfirmationService;
use App\Services\Conversations\ConversationAppointmentService;
use App\Services\Conversations\ConversationService;
use App\Services\Speech\Contracts\SpeechToTextProvider;
use App\Services\Speech\SpeechToTextException;
use App\Services\Widget\BotPublicAvailabilityService;
use App\Services\Widget\WidgetDomainValidator;
use App\Services\Widget\WidgetImageAttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WidgetApiController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ActionConfirmationService $actionConfirmationService,
        private readonly WidgetDomainValidator $domainValidator,
        private readonly BotWidgetAppearance $widgetAppearance,
        private readonly ConversationAppointmentService $appointmentService,
        private readonly ChannelResponseFormatter $responseFormatter,
        private readonly BotPublicAvailabilityService $availability,
        private readonly SpeechToTextProvider $speechToText,
        private readonly TeamEntitlementService $entitlements,
        private readonly WidgetImageAttachmentService $imageAttachments,
    ) {}

    public function session(WidgetSessionRequest $request, string $botPublicId): JsonResponse
    {
        $bot = $this->usableBot($botPublicId);
        $this->authorizeOrigin($request, $bot);
        $visitor = $this->visitor($bot, $request->validated('visitor_id'));
        $conversation = null;

        if (! $request->boolean('new_conversation') && $request->validated('conversation_id')) {
            $conversation = $bot->conversations()
                ->where('visitor_id', $visitor->id)
                ->where('public_id', $request->validated('conversation_id'))
                ->where('status', 'active')
                ->first();
        }

        try {
            $conversation ??= $this->conversationService->createWidgetConversation($bot, $visitor);
        } catch (PlanLimitExceededException) {
            return response()->json([
                'error' => 'unavailable',
                'message' => 'This assistant is temporarily unavailable.',
            ], 503);
        }

        $appearance = $this->widgetAppearance->for($bot);

        return response()->json([
            'conversation_id' => $conversation->public_id,
            'visitor_id' => $visitor->public_id,
            'handoff_status' => $conversation->handoff_status->value,
            'next_after_message_id' => $this->conversationService->publicMessages($conversation)->last()?->getKey(),
            'bot' => [
                'name' => $appearance['assistant_name'],
                'welcome_message' => $bot->welcome_message,
                'fallback_message' => $bot->fallback_message,
                'appearance' => $appearance,
                'availability' => $this->availability->status($bot),
                'platform_name' => (string) config('platform.marketing_name'),
                'platform_url' => (string) config('platform.marketing_url'),
                'capabilities' => [
                    'voice_input' => $this->entitlements->hasFeature($bot->team, PlanFeature::VoiceInput),
                ],
            ],
            'messages' => $this->messages($conversation),
        ]);
    }

    public function status(Request $request, string $botPublicId): JsonResponse
    {
        $bot = Bot::query()->where('public_id', $botPublicId)->firstOrFail();
        $this->authorizeOrigin($request, $bot);

        return response()->json([
            'availability' => $this->availability->status($bot),
        ]);
    }

    public function message(WidgetMessageRequest $request, string $botPublicId): JsonResponse
    {
        $bot = $this->usableBot($botPublicId);
        $this->authorizeOrigin($request, $bot);

        $visitor = $bot->visitors()
            ->where('public_id', $request->validated('visitor_id'))
            ->firstOrFail();
        $conversation = $bot->conversations()
            ->where('visitor_id', $visitor->id)
            ->where('public_id', $request->validated('conversation_id'))
            ->where('status', 'active')
            ->firstOrFail();

        $this->conversationService->persistWidgetWelcomeMessage($bot, $conversation);

        $attachments = [];
        $image = $request->file('image');

        if ($image instanceof UploadedFile) {
            try {
                $attachments[] = $this->imageAttachments->store($image);
            } catch (\Throwable $exception) {
                logger()->warning('conversation_attachment.received', [
                    'bot_id' => $bot->id,
                    'conversation_id' => $conversation->id,
                    'type' => 'image',
                    'failure_type' => 'storage',
                    'exception' => $exception::class,
                ]);

                return response()->json([
                    'error' => 'upload_failed',
                    'message' => 'Image could not be uploaded.',
                ], 422);
            }

            logger()->info('conversation_attachment.received', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'type' => 'image',
                'mime_type' => $attachments[0]['mime_type'],
                'size' => $attachments[0]['size'],
            ]);
        }

        try {
            $clientMessageId = $request->validated('client_message_id');

            $reply = $this->conversationService->sendInboundMessage(
                $bot,
                $conversation,
                ChannelInboundMessage::fromWebsite(
                    (string) $conversation->external_conversation_reference,
                    (string) $visitor->public_id,
                    (string) ($request->validated('message') ?? ''),
                    is_string($clientMessageId)
                        ? ['client_message_id' => $clientMessageId]
                        : [],
                    $attachments,
                ),
            );
        } catch (AiException $exception) {
            logger()->warning('Public widget request failed.', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => $attachments === [] ? 'unavailable' : 'vision_unavailable',
                'message' => $attachments !== []
                    ? 'I couldn\'t analyze that image right now.'
                    : (app()->environment('local')
                    ? $exception->getMessage()
                    : ($bot->fallback_message ?: 'Something went wrong. Please try again.')),
            ], 503);
        }

        $outbound = $this->responseFormatter->format($reply);

        return response()->json([
            'conversation_id' => $reply->conversation->public_id,
            'handoff_status' => $reply->conversation->handoff_status->value,
            'next_after_message_id' => $this->conversationService->publicMessages($reply->conversation)->last()?->getKey(),
            'message' => [
                ...$this->messagePayload($reply->assistantMessage, $reply->conversation),
                'blocks' => $outbound->blocks,
                'cards' => $outbound->cards,
            ],
            'user_message' => $this->messagePayload($reply->userMessage, $reply->conversation),
        ]);
    }

    public function attachment(Request $request, string $botPublicId, Message $message): StreamedResponse
    {
        $bot = Bot::query()->where('public_id', $botPublicId)->firstOrFail();
        $conversationId = $request->query('conversation_id');
        $visitorId = $request->query('visitor_id');

        abort_unless(is_string($conversationId) && is_string($visitorId), 404);

        $conversation = $bot->conversations()
            ->where('public_id', $conversationId)
            ->whereHas('visitor', fn ($query) => $query->where('public_id', $visitorId))
            ->firstOrFail();

        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);

        $attachment = collect((array) data_get($message->metadata, 'attachments', []))
            ->first(static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'image');

        abort_unless(is_array($attachment), 404);
        $stream = $this->imageAttachments->stream($attachment);
        $mimeType = is_string($attachment['mime_type'] ?? null)
            ? $attachment['mime_type']
            : 'application/octet-stream';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function transcribe(WidgetTranscriptionRequest $request, string $botPublicId): JsonResponse
    {
        $bot = $this->usableBot($botPublicId);
        $this->authorizeOrigin($request, $bot);

        if (! $this->entitlements->hasFeature($bot->team, PlanFeature::VoiceInput)) {
            return response()->json([
                'error' => 'voice_feature_unavailable',
                'message' => 'Voice input is available on paid plans. Please type your message.',
            ], 403);
        }

        $audio = $request->file('audio');

        if ($audio === null || $audio->getSize() === 0) {
            return response()->json([
                'error' => 'unsupported_audio',
                'message' => 'This recording is empty. Please try again or type your message.',
            ], 422);
        }

        $path = $audio->store('speech-transcriptions', 'local');

        if (! is_string($path)) {
            return response()->json([
                'error' => 'voice_unavailable',
                'message' => 'Voice input is temporarily unavailable. Please type your message.',
            ], 503);
        }

        try {
            $result = $this->speechToText->transcribe(
                Storage::disk('local')->path($path),
                $audio->getMimeType(),
                $request->validated('language'),
            );

            if (
                $result->durationSeconds !== null
                && $result->durationSeconds > (int) config('speech_to_text.max_duration_seconds', 60)
            ) {
                throw new SpeechToTextException(
                    'The recording is too long.',
                    'recording_too_long',
                );
            }

            logger()->info('Widget voice transcription completed.', [
                'bot_id' => $bot->id,
                'bytes' => $audio->getSize(),
                'language' => $result->language,
                'duration_seconds' => $result->durationSeconds,
            ]);

            return response()->json([
                'text' => $result->text,
                'language' => $result->language,
                'duration_seconds' => $result->durationSeconds,
            ]);
        } catch (SpeechToTextException $exception) {
            logger()->notice('Widget voice transcription failed.', [
                'bot_id' => $bot->id,
                'bytes' => $audio->getSize(),
                'category' => $exception->category,
            ]);

            $status = match ($exception->category) {
                'transcription_timeout' => 504,
                'rate_limited' => 429,
                'voice_unavailable' => 503,
                default => 422,
            };
            $message = match ($exception->category) {
                'empty_transcript' => 'No speech was detected. Please try again or type your message.',
                'recording_too_long' => 'That recording is too long. Please record a shorter message.',
                default => 'We could not transcribe that recording. Please try again or type your message.',
            };

            return response()->json([
                'error' => $exception->category,
                'message' => $message,
            ], $status);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    public function pollMessages(WidgetMessagesRequest $request, string $botPublicId): JsonResponse
    {
        [$bot, , $conversation] = $this->actionContext($request, $botPublicId);
        $messages = $this->conversationService->publicMessages(
            $conversation,
            $request->validated('after_message_id') !== null
                ? (int) $request->validated('after_message_id')
                : null,
        );

        return response()->json([
            'conversation_id' => $conversation->public_id,
            'handoff_status' => $conversation->handoff_status->value,
            'messages' => $this->messages($conversation, $messages),
            'next_after_message_id' => $messages->last()?->getKey(),
            'bot_public_id' => $bot->public_id,
        ]);
    }

    public function submitForm(
        WidgetFormSubmissionRequest $request,
        string $botPublicId,
        string $formReference,
    ): JsonResponse {
        [$bot, $visitor, $conversation] = $this->actionContext($request, $botPublicId);

        try {
            $reply = $this->conversationService->submitForm(
                $bot,
                $conversation,
                $formReference,
                (array) $request->validated('values'),
                $visitor,
            );
        } catch (AiException $exception) {
            logger()->warning('Public widget form submission failed.', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'error' => 'unavailable',
                'message' => $bot->fallback_message ?: 'Something went wrong. Please try again.',
            ], 503);
        }

        $outbound = $this->responseFormatter->format($reply);

        return response()->json([
            'conversation_id' => $reply->conversation->public_id,
            'form_block' => $reply->formBlock,
            'user_message' => $this->messagePayload($reply->userMessage, $reply->conversation),
            'message' => [
                ...$this->messagePayload($reply->assistantMessage, $reply->conversation),
                'blocks' => $outbound->blocks,
                'cards' => $outbound->cards,
            ],
        ]);
    }

    public function selectAppointment(
        WidgetAppointmentSelectionRequest $request,
        string $botPublicId,
        string $appointmentReference,
    ): JsonResponse {
        [$bot, $visitor, $conversation] = $this->actionContext($request, $botPublicId);

        try {
            $reply = $this->conversationService->submitAppointmentSlot(
                $bot,
                $conversation,
                $appointmentReference,
                (string) $request->validated('slot_reference'),
                $visitor,
            );
        } catch (HttpException $exception) {
            $block = $exception->getStatusCode() === 409
                ? $this->appointmentService->blockForReference($conversation, $appointmentReference)?->toArray()
                : null;

            return response()->json([
                'message' => $exception->getMessage(),
                'appointment_block' => $block,
            ], $exception->getStatusCode());
        } catch (AiException $exception) {
            logger()->warning('Public widget appointment selection failed.', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'error' => 'unavailable',
                'message' => $bot->fallback_message ?: 'Something went wrong. Please try again.',
            ], 503);
        }

        $outbound = $this->responseFormatter->format($reply);

        return response()->json([
            'conversation_id' => $reply->conversation->public_id,
            'appointment_block' => $reply->appointmentBlock,
            'user_message' => $this->messagePayload($reply->userMessage, $reply->conversation),
            'message' => [
                ...$this->messagePayload($reply->assistantMessage),
                'blocks' => $outbound->blocks,
                'cards' => $outbound->cards,
            ],
        ]);
    }

    public function confirm(
        WidgetActionRequest $request,
        string $botPublicId,
        string $actionReference,
    ): JsonResponse {
        [$bot, $visitor, $conversation] = $this->actionContext($request, $botPublicId);
        $result = $this->actionConfirmationService->confirm(
            $bot,
            ToolExecutionContext::forBot($bot, $conversation, null, $visitor),
            $actionReference,
        );

        return $this->actionResponse($result);
    }

    public function cancel(
        WidgetActionRequest $request,
        string $botPublicId,
        string $actionReference,
    ): JsonResponse {
        [$bot, $visitor, $conversation] = $this->actionContext($request, $botPublicId);
        $result = $this->actionConfirmationService->cancel(
            $bot,
            ToolExecutionContext::forBot($bot, $conversation, null, $visitor),
            $actionReference,
        );

        return $this->actionResponse($result);
    }

    private function usableBot(string $publicId): Bot
    {
        $bot = Bot::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        abort_unless($this->availability->isOnline($bot), 404);

        return $bot;
    }

    private function authorizeOrigin(Request $request, Bot $bot): void
    {
        abort_unless($this->domainValidator->isAllowed($request, $bot), 403);
    }

    /**
     * @return array{0: Bot, 1: WidgetVisitor, 2: Conversation}
     */
    private function actionContext(FormRequest $request, string $botPublicId): array
    {
        $bot = $this->usableBot($botPublicId);
        $this->authorizeOrigin($request, $bot);
        $visitor = $bot->visitors()
            ->where('public_id', $request->validated('visitor_id'))
            ->firstOrFail();
        $conversation = $bot->conversations()
            ->where('visitor_id', $visitor->id)
            ->where('public_id', $request->validated('conversation_id'))
            ->where('status', 'active')
            ->firstOrFail();

        return [$bot, $visitor, $conversation];
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

    private function visitor(Bot $bot, ?string $publicId): WidgetVisitor
    {
        $visitor = $publicId === null
            ? null
            : $bot->visitors()->where('public_id', $publicId)->first();

        if ($visitor !== null) {
            $visitor->update(['last_seen_at' => now()]);

            return $visitor;
        }

        return $bot->visitors()->create([
            'public_id' => (string) Str::uuid(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, Message>|null  $messages
     * @return list<array{id: int, role: string, content: string|null, created_at: string|null, source: string|null, sender: string|null, blocks: list<array<string, mixed>>, cards: list<array<string, mixed>>}>
     */
    private function messages(Conversation $conversation, ?Collection $messages = null): array
    {
        $messages ??= $this->conversationService->publicMessages($conversation);

        return array_values($messages
            ->map(fn (Message $message): array => $this->messagePayload($message, $conversation))
            ->all());
    }

    /**
     * Expose only the public shape of a message. Handoff metadata stays server-side.
     *
     * @return array{id: int, role: string, content: string|null, created_at: string|null, source: string|null, sender: string|null, blocks: list<array<string, mixed>>, cards: list<array<string, mixed>>}
     */
    private function messagePayload(Message $message, ?Conversation $conversation = null): array
    {
        $source = data_get($message->metadata, 'source');
        $isHuman = $source === 'human_agent';
        $isSystem = $message->role === 'system';
        $blocks = $isHuman || $isSystem ? [] : $this->conversationService->messageBlocks($message);

        $payloadConversation = $conversation ?? $message->conversation;
        $attachment = $payloadConversation instanceof Conversation
            && data_get($message->metadata, 'attachments') !== null
            ? $this->imageAttachments->publicPayload($message, $payloadConversation)
            : null;

        return [
            'id' => (int) $message->getKey(),
            'role' => (string) $message->role,
            'content' => $message->content === null ? null : (string) $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
            'source' => $this->messageSource($message),
            'sender' => $isHuman ? 'Support Team' : null,
            'blocks' => $blocks,
            'cards' => $isHuman || $isSystem ? [] : $this->cardsFromBlocks($blocks),
            'attachments' => $attachment === null ? [] : [$attachment],
        ];
    }

    /**
     * @return 'human'|'system'|null
     */
    private function messageSource(Message $message): ?string
    {
        if (data_get($message->metadata, 'source') === 'human_agent') {
            return 'human';
        }

        return $message->role === 'system' ? 'system' : null;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function cardsFromBlocks(array $blocks): array
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'product_cards') {
                continue;
            }

            $cards = data_get($block, 'data.cards');

            return is_array($cards) ? array_values($cards) : [];
        }

        return [];
    }
}
