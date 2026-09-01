<?php

namespace App\Services\Ai;

use App\Models\Bot;
use App\Services\Api\LiveOperationCapabilityService;
use Illuminate\Support\Str;

class BotPromptBuilder
{
    public function __construct(
        private readonly AiRules $rules,
        private readonly LiveOperationCapabilityService $liveOperations,
    ) {}

    /**
     * @param  array{datasets: list<array{slug: string, name: string, entityType: string, fields: list<array<string, mixed>>}>}  $context
     */
    public function build(Bot $bot, array $context): string
    {
        $lines = [
            'You are the catalog assistant for '.$bot->name.'.',
            ...$this->rules->all(),
        ];

        if ($this->liveOperations->has($bot, 'search_catalog')) {
            $lines[] = 'Live catalog search is connected for this bot. Current product questions must be answered from a search_catalog call made for the current customer message.';
        }

        if (is_string($bot->instructions) && trim($bot->instructions) !== '') {
            $lines[] = 'Bot instructions: '.Str::limit(Str::squish($bot->instructions), 2000);
        }

        $rules = $bot->rules()
            ->enabled()
            ->orderBy('priority')
            ->get(['name', 'description', 'config']);

        foreach ($rules as $rule) {
            $ruleText = $rule->description ?: data_get($rule->config, 'text');

            if (is_string($ruleText) && trim($ruleText) !== '') {
                $lines[] = 'Bot rule: '.Str::limit(Str::squish($ruleText), 500);
            }
        }

        $lines[] = 'Authorized datasets and search fields:';

        foreach ($context['datasets'] as $dataset) {
            $fields = collect($dataset['fields'])
                ->map(fn (array $field): string => sprintf(
                    '%s (%s; filterable=%s; sortable=%s; operators=%s)',
                    $field['key'],
                    $field['type'],
                    $field['filterable'] ? 'yes' : 'no',
                    $field['sortable'] ? 'yes' : 'no',
                    implode(',', $field['operators']),
                ))
                ->implode('; ');

            $lines[] = sprintf('%s [%s; type=%s]: %s', $dataset['slug'], $dataset['name'], $dataset['entityType'], $fields ?: 'no fields');
        }

        return implode("\n", $lines);
    }
}
