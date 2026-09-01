<?php

use App\Services\Widget\WidgetImageAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('widget image attachments are stored privately and converted to transient provider input', function (): void {
    Storage::fake('local');
    $service = app(WidgetImageAttachmentService::class);

    $attachment = $service->store(UploadedFile::fake()->image('catalog-photo.jpg'));
    $content = $service->inputContent('Do you have this?', [$attachment]);

    expect($attachment)
        ->toHaveKeys(['type', 'mime_type', 'storage_path', 'original_name', 'size'])
        ->and($attachment['type'])->toBe('image')
        ->and($attachment['mime_type'])->toBe('image/jpeg')
        ->and($attachment['storage_path'])->toStartWith('widget-attachments/')
        ->and(Storage::disk('local')->exists($attachment['storage_path']))->toBeTrue()
        ->and($content)->toBeArray()
        ->and($content[0])->toMatchArray(['type' => 'input_text', 'text' => 'Do you have this?'])
        ->and($content[1]['type'])->toBe('input_image')
        ->and($content[1]['image_url'])->toStartWith('data:image/jpeg;base64,')
        ->and($content[1]['image_url'])->not->toContain('widget-attachments/');
});

test('widget image input can contain no customer text', function (): void {
    Storage::fake('local');
    $service = app(WidgetImageAttachmentService::class);
    $attachment = $service->store(UploadedFile::fake()->image('catalog-photo.webp'));

    $content = $service->inputContent('', [$attachment]);

    expect($content)->toHaveCount(1)
        ->and($content[0]['type'])->toBe('input_image')
        ->and($content[0]['image_url'])->toStartWith('data:image/webp;base64,');
});
