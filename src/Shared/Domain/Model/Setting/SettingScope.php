<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model\Setting;

enum SettingScope: string
{
    case System = 'system';
    case User = 'user';
}
