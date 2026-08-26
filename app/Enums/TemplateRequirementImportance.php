<?php

namespace App\Enums;

enum TemplateRequirementImportance: string
{
    case Required = 'required';
    case Recommended = 'recommended';
    case Optional = 'optional';
}
