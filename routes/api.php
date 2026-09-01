<?php

use App\Http\Controllers\EmailWebhookController;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\SmsWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\WidgetApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('widget')
    ->middleware('throttle:widget')
    ->group(function (): void {
        Route::post('{botPublicId}/session', [WidgetApiController::class, 'session'])->name('widget.session');
        Route::get('{botPublicId}/status', [WidgetApiController::class, 'status'])->name('widget.status');
        Route::post('{botPublicId}/messages', [WidgetApiController::class, 'message'])->name('widget.messages');
        Route::get('{botPublicId}/attachments/{message}', [WidgetApiController::class, 'attachment'])
            ->middleware('signed')
            ->name('widget.attachments');
        Route::post('{botPublicId}/transcribe', [WidgetApiController::class, 'transcribe'])
            ->middleware('throttle:widget-speech')
            ->name('widget.transcribe');
        Route::get('{botPublicId}/messages', [WidgetApiController::class, 'pollMessages'])->name('widget.messages.poll');
        Route::post('{botPublicId}/actions/{actionReference}/confirm', [WidgetApiController::class, 'confirm'])
            ->name('widget.actions.confirm');
        Route::post('{botPublicId}/actions/{actionReference}/cancel', [WidgetApiController::class, 'cancel'])
            ->name('widget.actions.cancel');
        Route::post('{botPublicId}/forms/{formReference}/submit', [WidgetApiController::class, 'submitForm'])
            ->name('widget.forms.submit');
        Route::post('{botPublicId}/appointments/{appointmentReference}/select', [WidgetApiController::class, 'selectAppointment'])
            ->name('widget.appointments.select');
    });

Route::prefix('channels/whatsapp/webhook')
    ->middleware('throttle:whatsapp-webhook')
    ->group(function (): void {
        Route::get('/', [WhatsAppWebhookController::class, 'verify'])->name('channels.whatsapp.webhook.verify');
        Route::post('/', [WhatsAppWebhookController::class, 'receive'])->name('channels.whatsapp.webhook.receive');
    });

Route::prefix('channels/meta/webhook')
    ->middleware('throttle:meta-webhook')
    ->group(function (): void {
        Route::get('/', [MetaWebhookController::class, 'verify'])->name('channels.meta.webhook.verify');
        Route::post('/', [MetaWebhookController::class, 'receive'])->name('channels.meta.webhook.receive');
    });

Route::post('channels/telegram/{connection}/webhook', [TelegramWebhookController::class, 'receive'])
    ->name('channels.telegram.webhook.receive')
    ->middleware('throttle:telegram-webhook');

Route::post('channels/sms/twilio/{connection}/webhook', [SmsWebhookController::class, 'receive'])
    ->name('channels.sms.twilio.webhook.receive')
    ->middleware('throttle:sms-webhook');

Route::post('channels/email/{connection}/webhook', [EmailWebhookController::class, 'receive'])
    ->name('channels.email.webhook.receive')
    ->middleware('throttle:email-webhook');
