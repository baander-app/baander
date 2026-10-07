<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\Setting\SettingDefinition;

/**
 * Contributes the settings a context owns. Implementations are collected by
 * the setting definition registry through the container tag.
 */
interface SettingDefinitionProviderInterface
{
    /**
     * @return iterable<SettingDefinition>
     */
    public function definitions(): iterable;
}
