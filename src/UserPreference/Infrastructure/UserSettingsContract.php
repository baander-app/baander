<?php

declare(strict_types=1);

namespace App\UserPreference\Infrastructure;

use App\Shared\Domain\Model\Setting\SupportedLanguages;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Command\ResetUserSettingCommand;
use App\UserPreference\Application\Command\SetUserSettingCommand;
use App\UserPreference\Application\CommandHandler\ResetUserSettingHandler;
use App\UserPreference\Application\CommandHandler\SetUserSettingHandler;
use App\UserPreference\Application\DTO\UserSettingEntry;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use App\UserPreference\Application\Port\UserSettingView;
use App\UserPreference\Application\Service\UserSettingsReader;
use App\UserPreference\Application\Settings\LanguageSettingDefinitions;

/**
 * Runs the same handlers as the user settings API, called directly so a
 * caller's transaction and exceptions pass through unchanged.
 */
final readonly class UserSettingsContract implements UserSettingsContractInterface
{
    public function __construct(
        private UserSettingsReader $settings,
        private SetUserSettingHandler $setHandler,
        private ResetUserSettingHandler $resetHandler,
    ) {
    }

    public function resolveLanguage(string $userId): string
    {
        $language = $this->settings->entry(Uuid::fromString($userId), LanguageSettingDefinitions::LANGUAGE)->value;

        // The server default is only checked against its own definition, which offers the same languages.
        return is_string($language) && in_array($language, SupportedLanguages::codes(), true)
            ? $language
            : SupportedLanguages::FALLBACK;
    }

    public function seedLanguage(string $userId, string $language): void
    {
        $entry = $this->settings->entry(Uuid::fromString($userId), LanguageSettingDefinitions::LANGUAGE);
        if ($language === $entry->resetValue || !$entry->definition->allows($language)) {
            return;
        }

        ($this->setHandler)(new SetUserSettingCommand($userId, LanguageSettingDefinitions::LANGUAGE, $language));
    }

    public function settings(string $userId): array
    {
        return array_map($this->view(...), $this->settings->entries(Uuid::fromString($userId)));
    }

    public function setting(string $userId, string $key): UserSettingView
    {
        return $this->view($this->settings->entry(Uuid::fromString($userId), $key));
    }

    public function set(string $userId, string $key, mixed $value): void
    {
        ($this->setHandler)(new SetUserSettingCommand($userId, $key, $value));
    }

    public function reset(string $userId, string $key): void
    {
        ($this->resetHandler)(new ResetUserSettingCommand($userId, $key));
    }

    private function view(UserSettingEntry $entry): UserSettingView
    {
        $definition = $entry->definition;

        return new UserSettingView(
            key: $definition->key,
            label: $definition->label,
            type: $definition->type->value,
            options: $definition->options(),
            userEditable: $definition->isUserEditable(),
            storedValue: $entry->storedValue,
            storedValueValid: $entry->storedValueValid(),
            value: $entry->value,
            resetValue: $entry->resetValue,
            source: $entry->source->value,
        );
    }
}
