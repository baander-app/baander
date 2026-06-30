<?php

declare(strict_types=1);

namespace App\Notification\Application\Service;

use App\Notification\Domain\ValueObject\NotificationCategory;

final class NotificationContentResolver
{
    /**
     * Resolves translation keys and parameters from event data.
     *
     * @param array $payload The event's toPayload() output
     * @return array{titleKey: string, bodyKey: string, parameters: array{title: array, body: array}}
     */
    public function resolve(NotificationCategory $category, string $eventName, array $payload): array
    {
        return match ($eventName) {
            // Security
            'user.password_changed' => [
                'titleKey' => 'user.password_changed.title',
                'bodyKey' => 'user.password_changed.body',
                'parameters' => ['title' => [], 'body' => []],
            ],
            'user.passkey_registered' => [
                'titleKey' => 'user.passkey_registered.title',
                'bodyKey' => 'user.passkey_registered.body',
                'parameters' => [
                    'title' => [],
                    'body' => ['name' => $payload['name'] ?? 'Unknown'],
                ],
            ],
            'user.passkey_deleted' => [
                'titleKey' => 'user.passkey_deleted.title',
                'bodyKey' => 'user.passkey_deleted.body',
                'parameters' => ['title' => [], 'body' => []],
            ],
            'token.revoked' => [
                'titleKey' => 'token.revoked.title',
                'bodyKey' => 'token.revoked.body',
                'parameters' => [
                    'title' => [],
                    'body' => ['tokenType' => $payload['token_type'] ?? 'access'],
                ],
            ],
            'device_code.approved' => [
                'titleKey' => 'device_code.approved.title',
                'bodyKey' => 'device_code.approved.body',
                'parameters' => ['title' => [], 'body' => []],
            ],
            'user.registered' => [
                'titleKey' => 'user.registered.title',
                'bodyKey' => 'user.registered.body',
                'parameters' => [
                    'title' => [],
                    'body' => ['name' => $payload['name'] ?? 'User'],
                ],
            ],

            // Background Jobs
            'library.scan_completed' => [
                'titleKey' => 'library.scan_completed.title',
                'bodyKey' => 'library.scan_completed.body',
                'parameters' => [
                    'title' => [],
                    'body' => [
                        'filesDiscovered' => $payload['files_discovered'] ?? 0,
                        'filesProcessed' => $payload['files_processed'] ?? 0,
                    ],
                ],
            ],

            // Media Changes
            'album.created' => [
                'titleKey' => 'album.created.title',
                'bodyKey' => 'album.created.body',
                'parameters' => ['title' => [], 'body' => []],
            ],

            default => [
                'titleKey' => "{$eventName}.title",
                'bodyKey' => "{$eventName}.body",
                'parameters' => ['title' => [], 'body' => []],
            ],
        };
    }
}
