<?php

use Inertia\Testing\AssertableInertia as Assert;

test('welcome page receives the configured demo widget bot id', function (): void {
    config(['widget.demo_bot_id' => 'demo-bot-id']);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('demoWidgetBotId', 'demo-bot-id'));
});
