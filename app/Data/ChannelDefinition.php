<?php

namespace App\Data;

use App\Enums\ChannelCapability;
use App\Enums\ConversationChannel;

final readonly class ChannelDefinition
{
    /**
     * @param  list<ChannelCapability>  $capabilities
     */
    public function __construct(
        public ConversationChannel $key,
        public string $name,
        public string $description,
        public bool $implemented,
        public array $capabilities,
    ) {}

    /**
     * @return array{key: string, name: string, description: string, implemented: bool, capabilities: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'name' => $this->name,
            'description' => $this->description,
            'implemented' => $this->implemented,
            'capabilities' => array_map(
                static fn (ChannelCapability $capability): string => $capability->value,
                $this->capabilities,
            ),
        ];
    }
}
