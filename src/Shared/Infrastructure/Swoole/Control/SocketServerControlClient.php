<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Application\Port\ServerNotRunningException;

/**
 * Reaches the running web server through its control socket. Used outside the
 * server's HTTP workers, by console commands; the blocking stream calls are
 * deliberate there.
 */
final readonly class SocketServerControlClient implements ServerControlPortInterface
{
    /** Longer than the server's reply timeout, so a slow worker is reported as missing rather than as no answer. */
    public const float RESPONSE_TIMEOUT_SECONDS = ServerControlCoordinator::REPLY_TIMEOUT_SECONDS + 3.0;

    public function __construct(
        private string $socketPath,
        private float $timeoutSeconds = self::RESPONSE_TIMEOUT_SECONDS,
    ) {
    }

    public function execute(string $operation, array $payload = []): ServerControlResult
    {
        $id = bin2hex(random_bytes(8));
        $request = ControlProtocol::encodeRequest($id, $operation, $payload);
        $stream = $this->connect();
        try {
            $seconds = (int) $this->timeoutSeconds;
            stream_set_timeout($stream, $seconds, (int) (($this->timeoutSeconds - $seconds) * 1e6));
            if (fwrite($stream, $request) !== strlen($request)) {
                throw new ServerControlException('could not send the request to the web server control socket');
            }
            $response = fgets($stream);
            if ($response === false) {
                throw new ServerControlException(stream_get_meta_data($stream)['timed_out']
                    ? sprintf('the web server did not answer within %s seconds', $this->timeoutSeconds)
                    : 'the web server closed the control connection without answering');
            }

            return ControlProtocol::decodeResponse($response, $id);
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    private function connect()
    {
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client('unix://' . $this->socketPath, $errno, $error, $this->timeoutSeconds);
        if ($stream !== false) {
            return $stream;
        }
        // No socket file, or one left behind by a server that is gone.
        if ($errno === SOCKET_ENOENT || $errno === SOCKET_ECONNREFUSED || !file_exists($this->socketPath)) {
            throw new ServerNotRunningException();
        }

        throw new ServerControlException(sprintf(
            'cannot connect to the web server control socket %s: %s',
            $this->socketPath,
            $error !== '' ? $error : 'error ' . $errno,
        ));
    }
}
