<?php

namespace App\Enums;

enum CustomerCustomFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';
    case Select = 'select';
    case MultiSelect = 'multi_select';

    public function isChoice(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect], true);
    }
}
