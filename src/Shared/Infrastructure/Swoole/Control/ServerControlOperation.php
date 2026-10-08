<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

/**
 * One operation the server control channel can run inside an HTTP worker.
 *
 * Implementations are registered through the `app.server_control.operation` tag,
 * which config/services.yaml applies to every implementing service.
 */
interface ServerControlOperation
{
    /** Unique operation name, such as `qol.profile.set`. */
    public function name(): string;

    /**
     * True when the operation must run in every HTTP worker; false when the
     * accepting worker's answer is already server-wide (shared tables, server stats).
     */
    public function fansOut(): bool;

    /**
     * Runs in the current HTTP worker, inside a coroutine; it must not block.
     *
     * @param array<string, mixed> $payload
     *
     * @return mixed arrays and scalars only, so the in-server and socket paths return the same value
     */
    public function handle(array $payload): mixed;
}
