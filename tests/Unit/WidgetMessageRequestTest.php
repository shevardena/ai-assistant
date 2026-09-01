<?php

use App\Http\Requests\WidgetMessageRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

function widgetMessageValidation(array $data): bool
{
    return Validator::make($data, (new WidgetMessageRequest)->rules())->passes();
}

test('widget message validation accepts text-only and image-only messages', function (): void {
    expect(widgetMessageValidation([
        'visitor_id' => (string) Str::uuid(),
        'conversation_id' => (string) Str::uuid(),
        'message' => 'Find this',
    ]))->toBeTrue()
        ->and(widgetMessageValidation([
            'visitor_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'image' => UploadedFile::fake()->image('product.png'),
        ]))->toBeTrue()
        ->and(widgetMessageValidation([
            'visitor_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'message' => 'Find this',
            'image' => UploadedFile::fake()->image('product.jpg'),
        ]))->toBeTrue();
});

test('widget message validation rejects unsupported and oversized images', function (): void {
    expect(widgetMessageValidation([
        'visitor_id' => (string) Str::uuid(),
        'conversation_id' => (string) Str::uuid(),
        'image' => UploadedFile::fake()->create('payload.pdf', 10, 'application/pdf'),
    ]))->toBeFalse()
        ->and(widgetMessageValidation([
            'visitor_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'image' => UploadedFile::fake()->image('large.webp')->size(10241),
        ]))->toBeFalse();
});
