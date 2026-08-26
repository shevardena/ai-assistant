<?php

namespace App\Services\Conversations\Blocks;

final readonly class FormBlock implements ConversationBlock
{
    public const MAX_FIELDS = 10;

    public const MAX_OPTIONS = 20;

    public const MAX_REFERENCE_LENGTH = 64;

    public const MAX_TITLE_LENGTH = 120;

    public const MAX_DESCRIPTION_LENGTH = 500;

    public const MAX_SUBMIT_LABEL_LENGTH = 80;

    public const MAX_NAME_LENGTH = 64;

    public const MAX_LABEL_LENGTH = 100;

    public const MAX_PLACEHOLDER_LENGTH = 160;

    public const MAX_HELP_TEXT_LENGTH = 300;

    public const MAX_OPTION_VALUE_LENGTH = 100;

    public const MAX_OPTION_LABEL_LENGTH = 100;

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    public function __construct(
        public string $formReference,
        public ?string $title,
        public ?string $description,
        public array $fields,
        public string $submitLabel,
        public FormBlockStatus $status,
    ) {}

    /**
     * Normalize a trusted Laravel form definition into the canonical block shape.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function fromDefinition(
        string $formReference,
        array $definition,
        FormBlockStatus $status = FormBlockStatus::Pending,
    ): ?self {
        if (! self::validReference($formReference)) {
            return null;
        }

        $title = self::optionalText($definition, 'title', self::MAX_TITLE_LENGTH);
        $description = self::optionalText($definition, 'description', self::MAX_DESCRIPTION_LENGTH);
        $submitLabel = self::optionalText($definition, 'submit_label', self::MAX_SUBMIT_LABEL_LENGTH) ?? 'Continue';
        $rawFields = $definition['fields'] ?? null;

        if (($definition['title'] ?? null) !== null && $title === null
            || ($definition['description'] ?? null) !== null && $description === null
            || ($definition['submit_label'] ?? null) !== null && self::optionalText($definition, 'submit_label', self::MAX_SUBMIT_LABEL_LENGTH) === null
            || ! is_array($rawFields)
            || ! self::isList($rawFields)) {
            return null;
        }

        $fields = [];
        $seenNames = [];

        foreach ($rawFields as $rawField) {
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }

            if (! is_array($rawField)) {
                continue;
            }

            $field = self::normalizeField($rawField);

            if ($field === null || isset($seenNames[$field['name']])) {
                continue;
            }

            $seenNames[$field['name']] = true;
            $fields[] = $field;
        }

        return $fields === []
            ? null
            : new self($formReference, $title, $description, $fields, $submitLabel, $status);
    }

    public function type(): string
    {
        return ConversationBlockType::Form->value;
    }

    /**
     * @return array{type: 'form', data: array{form_reference: string, title?: string, description?: string, fields: list<array<string, mixed>>, submit_label: string, status: string}}
     */
    public function toArray(): array
    {
        $data = [
            'form_reference' => $this->formReference,
            'fields' => $this->fields,
            'submit_label' => $this->submitLabel,
            'status' => $this->status->value,
        ];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        return [
            'type' => ConversationBlockType::Form->value,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>|null
     */
    private static function normalizeField(array $field): ?array
    {
        $name = self::safeText($field['name'] ?? null, self::MAX_NAME_LENGTH);
        $label = self::safeText($field['label'] ?? null, self::MAX_LABEL_LENGTH);
        $type = is_string($field['type'] ?? null)
            ? FormBlockFieldType::tryFrom($field['type'])
            : null;
        $required = $field['required'] ?? false;

        if ($name === null
            || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1
            || $label === null
            || $type === null
            || ! is_bool($required)
            || self::isSensitiveField($name, $label)) {
            return null;
        }

        $normalized = [
            'name' => $name,
            'label' => $label,
            'type' => $type->value,
            'required' => $required,
        ];

        foreach (['placeholder' => self::MAX_PLACEHOLDER_LENGTH, 'help_text' => self::MAX_HELP_TEXT_LENGTH] as $key => $maximum) {
            if (! array_key_exists($key, $field)) {
                continue;
            }

            $value = self::safeText($field[$key], $maximum);

            if ($field[$key] !== null && $value === null) {
                return null;
            }

            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        if ($type === FormBlockFieldType::Select) {
            $options = self::normalizeOptions($field['options'] ?? null);

            if ($options === null) {
                return null;
            }

            $normalized['options'] = $options;
        }

        return $normalized;
    }

    /**
     * @return list<array{value: string, label: string}>|null
     */
    private static function normalizeOptions(mixed $options): ?array
    {
        if (! is_array($options) || ! self::isList($options)) {
            return null;
        }

        $normalized = [];
        $seenValues = [];

        foreach ($options as $option) {
            if (count($normalized) >= self::MAX_OPTIONS) {
                break;
            }

            if (! is_array($option)) {
                return null;
            }

            $value = self::safeText($option['value'] ?? null, self::MAX_OPTION_VALUE_LENGTH);
            $label = self::safeText($option['label'] ?? null, self::MAX_OPTION_LABEL_LENGTH);

            if ($value === null || $label === null || isset($seenValues[$value])) {
                return null;
            }

            $seenValues[$value] = true;
            $normalized[] = ['value' => $value, 'label' => $label];
        }

        return $normalized === [] ? null : $normalized;
    }

    private static function isSensitiveField(string $name, string $label): bool
    {
        return preg_match('/(?:password|passcode|api[_ -]?key|token|authorization|secret|credit[_ -]?card|card[_ -]?number|cvv|cvc|bank[_ -]?account)/i', $name.' '.$label) === 1;
    }

    private static function validReference(string $reference): bool
    {
        return mb_strlen($reference) <= self::MAX_REFERENCE_LENGTH
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $reference) === 1;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function optionalText(array $definition, string $key, int $maximum): ?string
    {
        return array_key_exists($key, $definition)
            ? self::safeText($definition[$key], $maximum)
            : null;
    }

    private static function safeText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ? null
            : mb_substr($value, 0, $maximum);
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function isList(array $values): bool
    {
        $keys = array_keys($values);

        return $keys === [] || $keys === range(0, count($values) - 1);
    }
}
