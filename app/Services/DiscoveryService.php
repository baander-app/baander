<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Local Network Discovery Service
 *
 * Listens for UDP broadcast discovery requests from Electron clients
 * and responds with server information (URL, name, version).
 *
 * Runs as a Swoole Process alongside the main HTTP server.
 */
class DiscoveryService
{
    protected bool $running = false;
    protected $socket = null;

    /**
     * Create and start the discovery UDP server
     *
     * This creates a UDP socket server for handling discovery requests.
     * When running as a Swoole Process, this will block and handle packets.
     */
    public function start(): void
    {
        if (!config('discovery.enabled')) {
            Log::info('Discovery service is disabled in configuration');
            return;
        }

        $port = config('discovery.port', 41234);

        Log::info('Starting discovery service', [
            'port' => $port,
            'server_name' => config('discovery.server_name'),
        ]);

        $this->running = true;

        // Create UDP socket using stream_socket_server
        $socket = stream_socket_server(
            "udp://0.0.0.0:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND
        );

        if (!$socket) {
            Log::error('Failed to create discovery socket', [
                'port' => $port,
                'error' => $errstr,
                'errno' => $errno,
            ]);
            return;
        }

        // Set socket to non-blocking mode
        stream_set_blocking($socket, false);

        $this->socket = $socket;

        Log::info('Discovery service listening', [
            'port' => $port,
            'pid' => getmypid(),
        ]);

        // Main loop - listen for packets
        while ($this->running) {
            // Use stream_select to wait for data with timeout
            $read = [$socket];
            $write = null;
            $except = null;

            // Wait for data up to 1 second
            $ready = stream_select($read, $write, $except, 1, 0);

            if ($ready === false) {
                // Error in stream_select
                Log::error('stream_select error');
                break;
            }

            if ($ready === 0) {
                // Timeout, continue loop
                continue;
            }

            // Data available, receive it
            $data = stream_socket_recvfrom($socket, 65535, 0, $peer);

            if ($data === false || $data === '') {
                continue;
            }

            $message = trim($data);

            // Validate discovery request
            if ($message !== 'BAANDER_DISCOVER') {
                Log::debug('Received non-discovery packet', [
                    'data' => substr($message, 0, 100),
                    'from' => $peer,
                ]);
                continue;
            }

            Log::info('Discovery request received', [
                'from' => $peer,
            ]);

            $response = $this->buildDiscoveryResponse();

            // Send response back to client
            $sendResult = stream_socket_sendto($socket, $response, 0, $peer);

            if ($sendResult === false) {
                Log::warning('Failed to send discovery response', [
                    'to' => $peer,
                ]);
            } else {
                Log::debug('Discovery response sent', [
                    'to' => $peer,
                    'bytes' => $sendResult,
                ]);
            }
        }

        // Close socket
        if (is_resource($socket)) {
            fclose($socket);
        }

        Log::info('Discovery service stopped');
    }

    /**
     * Build discovery response
     *
     * Returns JSON with server information
     */
    protected function buildDiscoveryResponse(): string
    {
        $data = [
            'name' => config('discovery.server_name'),
            'url' => config('app.url'),
            'version' => config('app.version', '1.0.0'),
            'api_version' => 'v1',
            'timestamp' => now()->toIso8601String(),
        ];

        $json = json_encode($data);

        if ($json === false) {
            Log::error('Failed to encode discovery response', [
                'error' => json_last_error_msg(),
            ]);
            return '{}';
        }

        return $json;
    }

    /**
     * Check if discovery service is running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Stop the discovery service
     */
    public function stop(): void
    {
        $this->running = false;

        if ($this->socket !== null && is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Shutdown the discovery service (alias for stop)
     */
    public function shutdown(): void
    {
        $this->stop();
    }
}
