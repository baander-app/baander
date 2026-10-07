<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model\Setting;

enum SettingValueType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Enum = 'enum';
    case String = 'string';
}
