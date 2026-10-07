<?php

declare(strict_types=1);

namespace App\UserPreference\Application\Port;

use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Exception\UnknownSettingException;

/**
 * What other contexts may do with a user's settings. Every read goes to the
 * stores afresh, so a long-running worker sees changes made elsewhere.
 */
interface UserSettingsContractInterface
{
    /**
     * The language to email the user in: their choice while it is still
     * offered, otherwise the server default while it is offered, otherwise English.
     */
    public function resolveLanguage(string $userId): string;

    /**
     * Stores the language as the user's choice when it is offered and differs
     * from the current server default. Joins the caller's open transaction.
     */
    public function seedLanguage(string $userId, string $language): void;

    /**
     * @return list<UserSettingView>
     */
    public function settings(string $userId): array;

    /**
     * @throws UnknownSettingException when no user setting has the key
     */
    public function setting(string $userId, string $key): UserSettingView;

    /**
     * Sets the user's choice on an administrator's behalf, including settings
     * the user may not change themselves.
     *
     * @throws UnknownSettingException       when no user setting has the key
     * @throws InvalidSettingValuesException when the value is not allowed
     */
    public function set(string $userId, string $key, mixed $value): void;

    /**
     * @throws UnknownSettingException when no user setting has the key
     */
    public function reset(string $userId, string $key): void;
}
