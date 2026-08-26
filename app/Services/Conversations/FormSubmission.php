<?php

namespace App\Services\Conversations;

use App\Services\Conversations\Blocks\FormBlock;

final readonly class FormSubmission
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        public FormBlock $block,
        public array $values,
        public string $displayMessage,
    ) {}
}
