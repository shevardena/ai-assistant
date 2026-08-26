<?php

namespace App\Services\Conversations\Blocks;

enum FormBlockFieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';
    case Textarea = 'textarea';
    case Number = 'number';
    case Select = 'select';
}
