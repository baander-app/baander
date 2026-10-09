<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use RuntimeException;
use Swoole\Server;
use Swoole\Server\Port;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Server\Configurator\Configurator;
use Throwable;

/**
 * Adds the server control listener before the server starts.
 *
 * The unix socket is mode 0600 in a 0700 directory owned by the server's user
 * (ControlSocketDirectory): file permissions are the authentication, so only
 * that user (operators running commands in the same container) can connect. The bundle tags this service as a
 * server configurator through autoconfiguration.
 */
final readonly class ControlSocketConfigurator implements Configurator
{
    public function __construct(
        private string $socketPath,
        private SwooleServerWorkers $workers,
        private ServerControlCoordinator $coordinator,
        private ControlPipeMessageHandler $pipeMessages,
        // Releases the pooled services an operation borrows when its coroutine ends;
        // the bundle does this only for requests and coroutines it starts itself.
        private ?CoWrapper $coWrapper = null,
    ) {
    }

    public function configure(Server $server): void
    {
        // ext-posix is a platform requirement; a development host may lack it.
        ControlSocketDirectory::prepare(
            dirname($this->socketPath),
            function_exists('posix_geteuid') ? posix_geteuid() : null,
        );
        $this->removeStaleSocket();

        $umask = umask(0077);
        try {
            $listener = $server->addListener($this->socketPath, 0, SWOOLE_SOCK_UNIX_STREAM);
        } finally {
            umask($umask);
        }
        if (!$listener instanceof Port) {
            throw new RuntimeException(sprintf('Cannot listen on the server control socket %s.', $this->socketPath));
        }
        if (!chmod($this->socketPath, 0600)) {
            throw new RuntimeException(sprintf('Cannot restrict the server control socket %s to mode 0600.', $this->socketPath));
        }

        $listener->set([
            'open_http_protocol' => false,
            'open_http2_protocol' => false,
            'open_websocket_protocol' => false,
            'open_eof_split' => true,
            'package_eof' => "\n",
            'package_max_length' => ControlProtocol::MAX_LINE_BYTES,
        ]);
        $listener->on('Receive', $this->receive(...));
        $server->on('PipeMessage', $this->pipeMessages->__invoke(...));
        $this->workers->attach($server);
    }

    /** Answers one request line; a rejected line leaves the connection and the listener open. */
    private function receive(Server $server, int $fd, int $reactorId, string $line): void
    {
        $this->coWrapper?->defer();
        $server->send($fd, $this->respond($line));
    }

    private function respond(string $line): string
    {
        try {
            $request = ControlProtocol::decodeRequest($line);
        } catch (ServerControlException $exception) {
            return ControlProtocol::encodeError(null, $exception->getMessage());
        }
        try {
            return ControlProtocol::encodeResult(
                $request['id'],
                $this->coordinator->execute($request['op'], $request['payload']),
            );
        } catch (ServerControlException $exception) {
            return ControlProtocol::encodeError($request['id'], $exception->getMessage());
        } catch (Throwable $exception) {
            return ControlProtocol::encodeError($request['id'], 'server control operation failed: ' . $exception->getMessage());
        }
    }

    /** A socket file left by a server that did not shut down cleanly would block the bind. */
    private function removeStaleSocket(): void
    {
        clearstatcache(true, $this->socketPath);
        if (!file_exists($this->socketPath) && !is_link($this->socketPath)) {
            return;
        }
        if (filetype($this->socketPath) !== 'socket') {
            throw new RuntimeException(sprintf('Refusing to replace %s: it is not a socket.', $this->socketPath));
        }
        if (!unlink($this->socketPath)) {
            throw new RuntimeException(sprintf('Cannot remove the stale server control socket %s.', $this->socketPath));
        }
    }
}
