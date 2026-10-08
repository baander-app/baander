<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\UserNotFoundException;
use App\Shared\Application\Actor;
use App\Shared\Application\Exception\InvalidSettingValuesException;
use App\Shared\Application\Exception\UnknownSettingException;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use App\UserPreference\Application\Port\UserSettingView;
use Psr\Log\LoggerInterface;

/**
 * Reads and changes a user's settings on an administrator's behalf. The admin
 * user settings API and `app:user:setting` both go through here, and every
 * change is logged with who made it and the stored value before and after.
 */
final readonly class AdminUserSettings
{
    /** The actor logged for a change made on the command line, where nobody is signed in. */
    public const string CLI_ACTOR = Actor::CLI;

    public function __construct(
        private UserLookup $users,
        private UserSettingsContractInterface $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $user the user's email address or UUID
     *
     * @return list<UserSettingView>
     *
     * @throws UserNotFoundException when no user has the email address or UUID
     */
    public function settings(string $user): array
    {
        return $this->settings->settings($this->userId($user));
    }

    /**
     * @param string $user the user's email address or UUID
     *
     * @throws UserNotFoundException   when no user has the email address or UUID
     * @throws UnknownSettingException when no user setting has the key
     */
    public function setting(string $user, string $key): UserSettingView
    {
        return $this->settings->setting($this->userId($user), $key);
    }

    /**
     * @param string $actorId the signed-in administrator's id, or {@see self::CLI_ACTOR}
     * @param string $user    the user's email address or UUID
     * @param mixed  $value   a CLI string or a JSON value, parsed against the setting's definition
     *
     * @throws UserNotFoundException         when no user has the email address or UUID
     * @throws UnknownSettingException       when no user setting has the key
     * @throws InvalidSettingValuesException when the value is not allowed
     */
    public function set(string $actorId, string $user, string $key, mixed $value): UserSettingView
    {
        $userId = $this->userId($user);
        $before = $this->settings->setting($userId, $key);
        $this->settings->set($userId, $key, $value);
        $after = $this->settings->setting($userId, $key);

        $this->logChange('An administrator changed a user setting.', $actorId, $userId, $before, $after);

        return $after;
    }

    /**
     * @param string $actorId the signed-in administrator's id, or {@see self::CLI_ACTOR}
     * @param string $user    the user's email address or UUID
     *
     * @throws UserNotFoundException   when no user has the email address or UUID
     * @throws UnknownSettingException when no user setting has the key
     */
    public function reset(string $actorId, string $user, string $key): UserSettingView
    {
        $userId = $this->userId($user);
        $before = $this->settings->setting($userId, $key);
        $this->settings->reset($userId, $key);
        $after = $this->settings->setting($userId, $key);

        $this->logChange('An administrator reset a user setting.', $actorId, $userId, $before, $after);

        return $after;
    }

    /**
     * @throws UserNotFoundException when no user has the email address or UUID
     */
    private function userId(string $user): string
    {
        return $this->users->byIdentifier($user)->getId()->toString();
    }

    /** Logs the stored values; null means the user had or has no choice. */
    private function logChange(string $message, string $actorId, string $userId, UserSettingView $before, UserSettingView $after): void
    {
        $this->logger->info($message, [
            'actor_id' => $actorId,
            'target_id' => $userId,
            'key' => $after->key,
            'old_value' => $before->storedValue,
            'new_value' => $after->storedValue,
        ]);
    }
}
