<?php

namespace App\Services\Channels\Contracts;

use App\Data\ChannelInboundMessage;
use App\Data\ChannelOutboundMessage;
use App\Models\ChannelConnection;
use App\Services\Channels\ChannelDeliveryResult;

interface ChannelAdapter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function receive(array $payload): ?ChannelInboundMessage;

    public function send(ChannelConnection $connection, ChannelOutboundMessage $message): ChannelDeliveryResult;
}
