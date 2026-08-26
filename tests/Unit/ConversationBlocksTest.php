<?php

use App\Models\Message;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Conversations\AppointmentSlotNormalizer;
use App\Services\Conversations\Blocks\AppointmentSlotsBlock;
use App\Services\Conversations\Blocks\AppointmentSlotsStatus;
use App\Services\Conversations\Blocks\ComparisonBlock;
use App\Services\Conversations\Blocks\ConfirmationBlock;
use App\Services\Conversations\Blocks\ConfirmationBlockStatus;
use App\Services\Conversations\Blocks\ConversationBlockNormalizer;
use App\Services\Conversations\Blocks\ConversationBlockType;
use App\Services\Conversations\Blocks\FormBlock;
use App\Services\Conversations\Blocks\FormBlockStatus;
use App\Services\Conversations\Blocks\LocationsBlock;
use App\Services\Conversations\Blocks\OrderStatusBlock;
use App\Services\Conversations\Blocks\ProductCardsBlock;
use App\Services\Conversations\Blocks\TrackingBlock;

test('appointment slots normalize trusted slots without exposing provider references', function () {
    $slots = app(AppointmentSlotNormalizer::class)->normalize([
        [
            'slot_reference' => 'provider-early',
            'starts_at' => '2026-09-01T10:00:00+04:00',
            'ends_at' => '2026-09-01T10:30:00+04:00',
            'label' => 'Morning',
            'provider_id' => 'must-not-leak',
        ],
        [
            'slot_reference' => 'provider-invalid',
            'starts_at' => '2026-09-01T10:30:00+04:00',
            'ends_at' => '2026-09-01T10:00:00+04:00',
        ],
    ], 'Asia/Tbilisi');
    $block = AppointmentSlotsBlock::fromDefinition(
        '123e4567-e89b-12d3-a456-426614174000',
        'Available times',
        'Asia/Tbilisi',
        $slots,
    );

    expect($block)->toBeInstanceOf(AppointmentSlotsBlock::class)
        ->and($block?->status)->toBe(AppointmentSlotsStatus::Pending)
        ->and($block?->slots)->toHaveCount(1)
        ->and($block?->toArray())->not->toContain('provider-early')
        ->and($block?->toArray())->not->toContain('must-not-leak');
});

test('appointment slot blocks enforce date and slot bounds and strict timestamps', function () {
    $slots = [];

    for ($day = 1; $day <= 8; $day++) {
        for ($slot = 0; $slot < 11; $slot++) {
            $hour = 9 + intdiv($slot, 2);
            $minute = $slot % 2 === 0 ? '00' : '30';
            $slots[] = [
                'slot_reference' => "day-{$day}-{$slot}",
                'starts_at' => sprintf('2026-09-%02dT%02d:%s:00+04:00', $day, $hour, $minute),
            ];
        }
    }
    $slots[] = [
        'slot_reference' => 'local-time',
        'starts_at' => '2026-09-01T12:00:00',
    ];

    $normalized = app(AppointmentSlotNormalizer::class)->normalize($slots, 'Asia/Tbilisi');

    expect($normalized)->toHaveCount(30)
        ->and(collect($normalized)->groupBy(fn (array $slot): string => substr($slot['starts_at'], 0, 10))->count())->toBeLessThanOrEqual(7)
        ->and(collect($normalized)->groupBy(fn (array $slot): string => substr($slot['starts_at'], 0, 10))->every(fn ($group): bool => $group->count() <= 10))->toBeTrue();
});

test('form blocks serialize a bounded canonical schema', function () {
    $block = FormBlock::fromDefinition('123e4567-e89b-12d3-a456-426614174000', [
        'title' => 'Contact details',
        'description' => 'Tell us how to reach you.',
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
        'submit_label' => 'Continue',
    ]);

    $payload = $block?->toArray();

    expect($block)->toBeInstanceOf(FormBlock::class)
        ->and($block?->type())->toBe(ConversationBlockType::Form->value)
        ->and($payload['type'])->toBe('form')
        ->and($payload['data']['form_reference'])->toBe('123e4567-e89b-12d3-a456-426614174000')
        ->and($payload['data']['title'])->toBe('Contact details')
        ->and($payload['data']['submit_label'])->toBe('Continue')
        ->and($payload['data']['status'])->toBe(FormBlockStatus::Pending->value)
        ->and($payload['data']['fields'])->toHaveCount(2);
});

test('form blocks reject unsafe fields and malformed select options', function () {
    expect(FormBlock::fromDefinition('123e4567-e89b-12d3-a456-426614174000', [
        'fields' => [[
            'name' => 'api_token',
            'label' => 'API token',
            'type' => 'text',
            'required' => true,
        ]],
    ]))->toBeNull()
        ->and(FormBlock::fromDefinition('123e4567-e89b-12d3-a456-426614174000', [
            'fields' => [[
                'name' => 'reason',
                'label' => 'Reason',
                'type' => 'select',
                'required' => true,
                'options' => [
                    ['value' => 'same', 'label' => 'One'],
                    ['value' => 'same', 'label' => 'Duplicate'],
                ],
            ]],
        ]))->toBeNull();
});

test('form blocks normalize only trusted canonical fields', function () {
    $blocks = app(ConversationBlockNormalizer::class)->normalize([[
        'type' => 'form',
        'data' => [
            'form_reference' => '123e4567-e89b-12d3-a456-426614174000',
            'title' => 'Contact',
            'fields' => [[
                'name' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
                'unexpected' => 'discarded',
            ]],
            'submit_label' => 'Continue',
            'status' => 'pending',
            'team_id' => 999,
        ],
    ]]);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['data'])->not->toHaveKey('team_id')
        ->and($blocks[0]['data']['fields'][0])->not->toHaveKey('unexpected');
});

test('comparison blocks serialize with safe item and field data', function () {
    $block = new ComparisonBlock(
        items: [
            ['product_reference' => 'sku-1', 'label' => 'Laptop A'],
            ['product_reference' => 'sku-2', 'label' => 'Laptop B'],
        ],
        fields: [
            ['key' => 'price', 'label' => 'Price', 'values' => [1299, 1599]],
            ['key' => 'in_stock', 'label' => 'In stock', 'values' => [true, false]],
        ],
    );

    expect($block->type())->toBe(ConversationBlockType::Comparison->value)
        ->and($block->toArray())->toBe([
            'type' => 'comparison',
            'data' => [
                'items' => [
                    ['product_reference' => 'sku-1', 'label' => 'Laptop A'],
                    ['product_reference' => 'sku-2', 'label' => 'Laptop B'],
                ],
                'fields' => [
                    ['key' => 'price', 'label' => 'Price', 'values' => [1299, 1599]],
                    ['key' => 'in_stock', 'label' => 'In stock', 'values' => [true, false]],
                ],
            ],
        ]);
});

test('order status blocks serialize with an optional status and scalar fields', function () {
    $block = new OrderStatusBlock(
        status: 'processing',
        fields: [
            ['key' => 'updated_at', 'label' => 'Updated', 'value' => '2026-08-22T10:30:00Z'],
            ['key' => 'is_paid', 'label' => 'Paid', 'value' => true],
            ['key' => 'estimated_delivery', 'label' => 'Estimated delivery', 'value' => null],
        ],
    );

    expect($block->type())->toBe(ConversationBlockType::OrderStatus->value)
        ->and($block->toArray())->toBe([
            'type' => 'order_status',
            'data' => [
                'status' => 'processing',
                'fields' => [
                    ['key' => 'updated_at', 'label' => 'Updated', 'value' => '2026-08-22T10:30:00Z'],
                    ['key' => 'is_paid', 'label' => 'Paid', 'value' => true],
                    ['key' => 'estimated_delivery', 'label' => 'Estimated delivery', 'value' => null],
                ],
            ],
        ]);
});

test('tracking blocks serialize canonical shipment fields and safe generic values', function () {
    $block = new TrackingBlock(
        status: 'in_transit',
        carrier: 'DHL',
        trackingReference: 'ABC123',
        estimatedDelivery: '2026-08-27',
        latestEvent: 'Departed facility',
        trackingUrl: 'https://example.test/track/ABC123',
        fields: [
            ['key' => 'service_level', 'label' => 'Service level', 'value' => 'Express'],
            ['key' => 'is_delayed', 'label' => 'Delayed', 'value' => false],
        ],
    );

    expect($block->type())->toBe(ConversationBlockType::Tracking->value)
        ->and($block->toArray())->toMatchArray([
            'type' => 'tracking',
            'data' => [
                'status' => 'in_transit',
                'carrier' => 'DHL',
                'tracking_reference' => 'ABC123',
                'estimated_delivery' => '2026-08-27',
                'latest_event' => 'Departed facility',
                'tracking_url' => 'https://example.test/track/ABC123',
                'fields' => [
                    ['key' => 'service_level', 'label' => 'Service level', 'value' => 'Express'],
                    ['key' => 'is_delayed', 'label' => 'Delayed', 'value' => false],
                ],
            ],
        ]);
});

test('locations blocks serialize canonical map-ready fields and safe extras', function () {
    $block = new LocationsBlock([
        [
            'name' => 'Downtown Store',
            'address' => '123 Main Street',
            'city' => 'New York',
            'region' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'latitude' => 40.7501,
            'longitude' => -73.9967,
            'distance' => 1.8,
            'distance_unit' => 'km',
            'phone' => '+1 212 555 0100',
            'hours' => 'Mon-Fri 9-6',
            'url' => 'https://example.test/locations/downtown',
            'fields' => [
                ['key' => 'pickup_available', 'label' => 'Pickup available', 'value' => true],
            ],
        ],
    ]);

    expect($block->type())->toBe(ConversationBlockType::Locations->value)
        ->and($block->toArray())->toBe([
            'type' => 'locations',
            'data' => [
                'locations' => [[
                    'name' => 'Downtown Store',
                    'address' => '123 Main Street',
                    'city' => 'New York',
                    'region' => 'NY',
                    'postal_code' => '10001',
                    'country' => 'US',
                    'latitude' => 40.7501,
                    'longitude' => -73.9967,
                    'distance' => 1.8,
                    'distance_unit' => 'km',
                    'phone' => '+1 212 555 0100',
                    'hours' => 'Mon-Fri 9-6',
                    'url' => 'https://example.test/locations/downtown',
                    'fields' => [
                        ['key' => 'pickup_available', 'label' => 'Pickup available', 'value' => true],
                    ],
                ]],
            ],
        ]);
});

test('product cards serialize as a typed conversation block', function () {
    $block = new ProductCardsBlock([
        [
            'id' => 'sku-1',
            'title' => 'Laptop',
            'image' => null,
            'subtitle' => null,
            'description' => null,
            'price' => 20,
            'old_price' => null,
            'discount' => null,
            'url' => 'https://example.com/laptop',
            'button_label' => 'View product',
            'styles' => [],
        ],
    ]);

    $payload = $block->toArray();

    expect($block->type())->toBe(ConversationBlockType::ProductCards->value)
        ->and($payload['type'])->toBe('product_cards')
        ->and($payload['data']['cards'][0]['id'])->toBe('sku-1')
        ->and($payload['data']['cards'][0]['title'])->toBe('Laptop');
});

test('legacy product card metadata is normalized into a safe block', function () {
    $message = new Message;
    $message->setAttribute('metadata', [
        'cards' => [[
            'id' => 'sku-1',
            'title' => 'Laptop',
            'url' => 'https://example.com/laptop',
            'secret' => 'must-not-survive',
        ]],
    ]);

    $blocks = app(ConversationBlockNormalizer::class)->forMessage($message);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('product_cards')
        ->and($blocks[0]['data']['cards'][0])->not->toHaveKey('secret')
        ->and($blocks[0]['data']['cards'][0]['url'])->toBe('https://example.com/laptop');
});

test('canonical product card blocks restore from message metadata', function () {
    $message = new Message;
    $message->setAttribute('metadata', [
        'blocks' => [[
            'type' => 'product_cards',
            'data' => [
                'cards' => [[
                    'id' => 'sku-2',
                    'title' => 'Phone',
                ]],
            ],
        ]],
    ]);

    $blocks = app(ConversationBlockNormalizer::class)->forMessage($message);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('product_cards')
        ->and($blocks[0]['data']['cards'][0]['id'])->toBe('sku-2');
});

test('unknown and malformed blocks are ignored safely', function () {
    $blocks = app(ConversationBlockNormalizer::class)->normalize([
        ['type' => 'unknown', 'data' => ['secret' => 'value']],
        ['type' => 'product_cards', 'data' => ['cards' => [['title' => 'Missing id']]]],
        ['type' => 'comparison', 'data' => ['items' => [['product_reference' => 'sku-1', 'label' => 'Only one']], 'fields' => []]],
        ['type' => 'comparison', 'data' => ['items' => [['product_reference' => 'sku-1', 'label' => 'A'], ['product_reference' => 'sku-2', 'label' => 'B']], 'fields' => [['key' => 'secret', 'label' => 'Secret', 'values' => [['not' => 'scalar'], ['also' => 'not scalar']]]]]],
        ['type' => 'product_cards', 'data' => ['cards' => [['id' => 'sku-2', 'title' => 'Phone']]]],
    ]);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe(ConversationBlockType::ProductCards->value)
        ->and($blocks[0]['data']['cards'][0]['id'])->toBe('sku-2');
});

test('comparison metadata is restored with bounded scalar fields only', function () {
    $fields = [];

    for ($index = 0; $index < ComparisonBlock::MAX_FIELDS + 4; $index++) {
        $fields[] = [
            'key' => 'field-'.$index,
            'label' => 'Field '.$index,
            'values' => [$index, null],
            'unexpected' => 'discarded',
        ];
    }

    $message = new Message;
    $message->setAttribute('metadata', [
        'blocks' => [[
            'type' => 'comparison',
            'data' => [
                'items' => [
                    ['product_reference' => 'sku-1', 'label' => 'Laptop A', 'id' => 10],
                    ['product_reference' => 'sku-2', 'label' => 'Laptop B', 'id' => 11],
                ],
                'fields' => $fields,
            ],
        ]],
    ]);

    $blocks = app(ConversationBlockNormalizer::class)->forMessage($message);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['data']['items'][0])->toBe([
            'product_reference' => 'sku-1',
            'label' => 'Laptop A',
        ])
        ->and($blocks[0]['data']['fields'])->toHaveCount(ComparisonBlock::MAX_FIELDS)
        ->and($blocks[0]['data']['fields'][0])->not->toHaveKey('unexpected')
        ->and($blocks[0]['data']['fields'][0]['values'])->toBe([0, null]);
});

test('comparison normalization bounds item columns', function () {
    $items = [];
    $values = [];

    for ($index = 0; $index < ComparisonBlock::MAX_ITEMS + 3; $index++) {
        $items[] = [
            'product_reference' => 'sku-'.$index,
            'label' => 'Product '.$index,
        ];
        $values[] = 'Value '.$index;
    }

    $blocks = app(ConversationBlockNormalizer::class)->normalize([[
        'type' => 'comparison',
        'data' => [
            'items' => $items,
            'fields' => [[
                'key' => 'feature',
                'label' => 'Feature',
                'values' => $values,
            ]],
        ],
    ]]);

    expect($blocks[0]['data']['items'])->toHaveCount(ComparisonBlock::MAX_ITEMS)
        ->and($blocks[0]['data']['fields'][0]['values'])->toHaveCount(ComparisonBlock::MAX_ITEMS);
});

test('order status normalization drops malformed values and bounds fields', function () {
    $fields = [];

    for ($index = 0; $index < OrderStatusBlock::MAX_FIELDS + 3; $index++) {
        $fields[] = [
            'key' => 'field_'.$index,
            'label' => 'Field '.$index,
            'value' => $index,
            'unexpected' => 'discarded',
        ];
    }
    $fields[] = [
        'key' => 'nested',
        'label' => 'Nested value',
        'value' => ['secret' => 'discarded'],
    ];

    $blocks = app(ConversationBlockNormalizer::class)->normalize([[
        'type' => 'order_status',
        'data' => [
            'status' => 'awaiting_fulfillment',
            'fields' => $fields,
            'internal_id' => 'not allowed',
        ],
    ]]);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['data']['status'])->toBe('awaiting_fulfillment')
        ->and($blocks[0]['data']['fields'])->toHaveCount(OrderStatusBlock::MAX_FIELDS)
        ->and($blocks[0]['data']['fields'][0])->not->toHaveKey('unexpected')
        ->and(collect($blocks[0]['data']['fields'])->pluck('key')->all())->not->toContain('nested');
});

test('order status history restores a snapshot without re-fetching', function () {
    $message = new Message;
    $message->setAttribute('metadata', [
        'blocks' => [[
            'type' => 'order_status',
            'data' => [
                'status' => 'shipped',
                'fields' => [[
                    'key' => 'updated_at',
                    'label' => 'Updated',
                    'value' => '2026-08-22T10:30:00Z',
                ]],
            ],
        ]],
    ]);

    expect(app(ConversationBlockNormalizer::class)->forMessage($message))->toBe([
        [
            'type' => 'order_status',
            'data' => [
                'status' => 'shipped',
                'fields' => [[
                    'key' => 'updated_at',
                    'label' => 'Updated',
                    'value' => '2026-08-22T10:30:00Z',
                ]],
            ],
        ],
    ]);
});

test('tracking normalization validates URLs, scalars, and field bounds', function () {
    $fields = [];

    $fields[] = [
        'key' => 'nested',
        'label' => 'Nested value',
        'value' => ['secret' => 'discarded'],
    ];

    for ($index = 0; $index < TrackingBlock::MAX_FIELDS + 3; $index++) {
        $fields[] = [
            'key' => 'field_'.$index,
            'label' => 'Field '.$index,
            'value' => $index,
            'unexpected' => 'discarded',
        ];
    }
    $blocks = app(ConversationBlockNormalizer::class)->normalize([[
        'type' => 'tracking',
        'data' => [
            'status' => 'out_for_delivery',
            'tracking_url' => 'javascript:alert(1)',
            'fields' => $fields,
            'internal_shipment_id' => 'not allowed',
        ],
    ]]);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['data'])->not->toHaveKey('tracking_url')
        ->and($blocks[0]['data'])->not->toHaveKey('internal_shipment_id')
        ->and($blocks[0]['data']['fields'])->toHaveCount(TrackingBlock::MAX_FIELDS)
        ->and($blocks[0]['data']['fields'][0])->not->toHaveKey('unexpected')
        ->and(collect($blocks[0]['data']['fields'])->pluck('key')->all())->not->toContain('nested');
});

test('tracking history restores a saved snapshot without re-fetching', function () {
    $message = new Message;
    $message->setAttribute('metadata', [
        'blocks' => [[
            'type' => 'tracking',
            'data' => [
                'status' => 'delivered',
                'carrier' => 'DHL',
                'tracking_reference' => 'ABC123',
                'fields' => [],
            ],
        ]],
    ]);

    expect(app(ConversationBlockNormalizer::class)->forMessage($message))->toBe([
        [
            'type' => 'tracking',
            'data' => [
                'status' => 'delivered',
                'carrier' => 'DHL',
                'tracking_reference' => 'ABC123',
                'fields' => [],
            ],
        ],
    ]);
});

test('locations normalization keeps safe records and discards unsafe fields', function () {
    $locations = [[
        'store_name' => 'Downtown Store',
        'street_address' => '123 Main Street',
        'city' => 'New York',
        'state' => 'NY',
        'zip' => '10001',
        'country_code' => 'US',
        'lat' => '40.7501',
        'lng' => '-73.9967',
        'distance_miles' => '1.8',
        'url' => 'javascript:alert(1)',
        'pickup_available' => true,
        'internal_store_id' => 'internal-1',
        'private_notes' => 'not allowed',
        'nested' => ['secret' => 'discarded'],
    ]];

    for ($index = 1; $index <= LocationsBlock::MAX_LOCATIONS + 3; $index++) {
        $locations[] = ['name' => 'Store '.$index];
    }

    $blocks = app(ConversationBlockNormalizer::class)->normalize([[
        'type' => 'locations',
        'data' => ['locations' => $locations],
    ]]);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['data']['locations'])->toHaveCount(LocationsBlock::MAX_LOCATIONS)
        ->and($blocks[0]['data']['locations'][0])->toBe([
            'name' => 'Downtown Store',
            'address' => '123 Main Street',
            'city' => 'New York',
            'region' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'latitude' => 40.7501,
            'longitude' => -73.9967,
            'distance' => 1.8,
            'distance_unit' => 'miles',
            'fields' => [[
                'key' => 'pickup_available',
                'label' => 'Pickup available',
                'value' => true,
            ]],
        ])
        ->and($blocks[0]['data']['locations'][0])->not->toHaveKey('url')
        ->and($blocks[0]['data']['locations'][0])->not->toHaveKey('internal_store_id')
        ->and($blocks[0]['data']['locations'][0])->not->toHaveKey('nested');
});

test('locations with invalid coordinates remain renderable without coordinates', function () {
    $blocks = app(ConversationBlockNormalizer::class)->normalize([[
        'type' => 'locations',
        'data' => [
            'locations' => [[
                'name' => 'Invalid Coordinates Store',
                'latitude' => 91,
                'longitude' => -181,
            ]],
        ],
    ]]);

    expect($blocks)->toBe([[
        'type' => 'locations',
        'data' => [
            'locations' => [['name' => 'Invalid Coordinates Store']],
        ],
    ]]);
});

test('empty locations and malformed location blocks produce no block', function () {
    $normalizer = app(ConversationBlockNormalizer::class);

    expect($normalizer->normalize([[
        'type' => 'locations',
        'data' => ['locations' => []],
    ]]))->toBe([])
        ->and($normalizer->normalize([[
            'type' => 'locations',
            'data' => ['locations' => [['nested' => ['value' => 'unsafe']]]],
        ]]))->toBe([]);
});

test('locations history restores a saved snapshot without re-fetching', function () {
    $message = new Message;
    $message->setAttribute('metadata', [
        'blocks' => [[
            'type' => 'locations',
            'data' => [
                'locations' => [[
                    'name' => 'Airport Store',
                    'latitude' => 40.6413,
                    'longitude' => -73.7781,
                    'fields' => [],
                ]],
            ],
        ]],
    ]);

    expect(app(ConversationBlockNormalizer::class)->forMessage($message))->toBe([
        [
            'type' => 'locations',
            'data' => [
                'locations' => [[
                    'name' => 'Airport Store',
                    'latitude' => 40.6413,
                    'longitude' => -73.7781,
                ]],
            ],
        ],
    ]);
});

test('confirmation proposals expose a trusted pending block without changing model data', function () {
    $result = ToolResult::requiresConfirmation(
        '123e4567-e89b-12d3-a456-426614174000',
        'Add Laptop X to your cart.',
    );

    expect($result->modelData())->toMatchArray([
        'requires_confirmation' => true,
        'action_reference' => '123e4567-e89b-12d3-a456-426614174000',
    ])
        ->and($result->blocks)->toBe([
            (new ConfirmationBlock(
                actionReference: '123e4567-e89b-12d3-a456-426614174000',
                summary: 'Add Laptop X to your cart.',
                status: ConfirmationBlockStatus::Pending,
            ))->toArray(),
        ]);

    expect(app(ConversationBlockNormalizer::class)->normalize([
        ...$result->blocks,
        ['type' => 'confirmation', 'data' => ['status' => 'pending']],
    ]))->toBe($result->blocks);
});
