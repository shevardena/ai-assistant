<?php

use App\Services\Actions\ActionPresentationService;

test('formats known safe action results without returning raw payloads', function () {
    $presentation = new ActionPresentationService;

    expect($presentation->resultSummary('capture_lead', [
        'lead_reference' => 'LEAD-123',
        'email' => 'private@example.com',
    ]))->toBe('Lead submitted. Reference: LEAD-123')
        ->and($presentation->resultSummary('book_appointment', [
            'start_at' => '2026-08-28T15:00:00+04:00',
            'internal_note' => 'private',
        ]))->toBe('Appointment booked for Aug 28, 2026 3:00 PM.');
});

test('formats failures and malformed results safely', function () {
    $presentation = new ActionPresentationService;

    expect($presentation->errorSummary('slot_unavailable'))->toBe('Slot no longer available.')
        ->and($presentation->errorSummary('unknown-provider-code'))->toBe('The action could not be completed.')
        ->and($presentation->resultSummary('add_to_cart', null))->toBeNull()
        ->and($presentation->resultSummary('add_to_cart', ['unexpected' => ['private']]))->toBe('Item added to cart.');
});
