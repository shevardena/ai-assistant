<?php

namespace App\Services\Widget;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

final class WidgetImageAttachmentService
{
    /**
     * Store a validated widget image without trusting its client filename.
     *
     * @return array{type: 'image', mime_type: string, storage_path: string, original_name: string, size: int}
     */
    public function store(UploadedFile $file): array
    {
        $mimeType = $file->getMimeType();
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('The uploaded image type is not supported.'),
        };
        $path = $file->storeAs(
            'widget-attachments',
            (string) Str::uuid().'.'.$extension,
            'local',
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The uploaded image could not be stored.');
        }

        return [
            'type' => 'image',
            'mime_type' => $mimeType,
            'storage_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * Build the provider representation in memory. The encoded bytes are never persisted.
     *
     * @param  list<array<string, mixed>>  $attachments
     * @return string|list<array{type: 'input_text'|'input_image', text?: string, image_url?: string, detail?: string}>
     */
    public function inputContent(string $text, array $attachments): string|array
    {
        $images = array_values(array_filter(
            $attachments,
            static fn (mixed $attachment): bool => is_array($attachment)
                && ($attachment['type'] ?? null) === 'image',
        ));

        if ($images === []) {
            return $text;
        }

        $content = [];

        if ($text !== '') {
            $content[] = ['type' => 'input_text', 'text' => $text];
        }

        foreach ($images as $image) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $this->dataUri($image),
                'detail' => 'auto',
            ];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    public function dataUri(array $attachment): string
    {
        $path = $attachment['storage_path'] ?? null;
        $mimeType = $attachment['mime_type'] ?? null;

        if (! is_string($path) || ! preg_match('/\Awidget-attachments\/[A-Za-z0-9-]+\.(?:jpg|png|webp)\z/', $path)) {
            throw new AiException('The uploaded image could not be read.');
        }

        if (! is_string($mimeType) || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new AiException('The uploaded image type is not supported.');
        }

        try {
            $contents = Storage::disk('local')->get($path);
        } catch (\Throwable $exception) {
            throw new AiException('The uploaded image could not be read.', previous: $exception);
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    /**
     * @return array{type: 'image', mime_type: string, url: string, name: string, size: int}|null
     */
    public function publicPayload(Message $message, Conversation $conversation): ?array
    {
        $attachment = collect((array) data_get($message->metadata, 'attachments', []))
            ->first(static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'image');

        if (! is_array($attachment) || ! is_string($conversation->public_id) || $conversation->visitor === null) {
            return null;
        }

        $bot = $conversation->bot;

        if ($bot === null || ! is_string($attachment['mime_type'] ?? null)) {
            return null;
        }

        return [
            'type' => 'image',
            'mime_type' => $attachment['mime_type'],
            'url' => URL::signedRoute('widget.attachments', [
                'botPublicId' => $bot->public_id,
                'message' => $message->getKey(),
                'visitor_id' => $conversation->visitor->public_id,
                'conversation_id' => $conversation->public_id,
            ]),
            'name' => is_string($attachment['original_name'] ?? null) ? $attachment['original_name'] : 'Uploaded image',
            'size' => (int) ($attachment['size'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    public function stream(array $attachment): mixed
    {
        $path = $attachment['storage_path'] ?? null;

        if (! is_string($path) || ! preg_match('/\Awidget-attachments\/[A-Za-z0-9-]+\.(?:jpg|png|webp)\z/', $path)) {
            abort(404);
        }

        $stream = Storage::disk('local')->readStream($path);

        if (! is_resource($stream)) {
            abort(404);
        }

        return $stream;
    }
}
