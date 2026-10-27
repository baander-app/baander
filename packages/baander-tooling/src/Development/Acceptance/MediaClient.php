<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

final class MediaClient
{
    private \OpenSSLAsymmetricKey $key;
    /** @var array<string,string> */
    public readonly array $jwk;
    public ?string $token = null;
    private ?string $nonce = null;
    public readonly string $origin;

    public function __construct(string $origin, private readonly string $ca)
    {
        $this->origin = rtrim($origin, '/');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        Support::check($key !== false, 'Cannot generate DPoP signing key.');
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        Support::check(
            is_array($details) && isset($details['ec']['x'], $details['ec']['y']),
            'Cannot read DPoP public key.',
        );
        $this->jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::b64($details['ec']['x']),
            'y' => self::b64($details['ec']['y']),
        ];
    }

    private static function b64(string $value): string
    {
        return $value
            |> base64_encode(...)
            |> (static fn(string $encoded): string => strtr($encoded, '+/', '-_'))
            |> (static fn(string $encoded): string => rtrim($encoded, '='));
    }

    public function proof(string $method, string $url): string
    {
        $claims = [
            'htm' => $method,
            'htu' => preg_replace('/[?#].*$/', '', $url),
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ];
        if ($this->nonce !== null) {
            $claims['nonce'] = $this->nonce;
        }
        if ($this->token !== null) {
            $claims['ath'] = self::b64(hash('sha256', $this->token, true));
        }
        $header = ['typ' => 'dpop+jwt', 'alg' => 'ES256', 'jwk' => $this->jwk];
        $message = self::b64(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '.'
            . self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        Support::check(
            openssl_sign($message, $signature, $this->key, OPENSSL_ALGO_SHA256),
            'Cannot sign DPoP proof.',
        );
        // OpenSSL returns a DER sequence of two integers; JWT ES256 needs two 32-byte values.
        Support::check(
            ord($signature[0]) === 0x30 && ord($signature[2]) === 0x02,
            'Invalid ECDSA signature.',
        );
        $rLength = ord($signature[3]);
        $r = substr($signature, 4, $rLength);
        $sOffset = 4 + $rLength;
        Support::check(ord($signature[$sOffset]) === 0x02, 'Invalid ECDSA signature.');
        $s = substr($signature, $sOffset + 2, ord($signature[$sOffset + 1]));
        $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
        Support::check(strlen($raw) === 64, 'Invalid ES256 signature size.');
        return $message . '.' . self::b64($raw);
    }

    /** @param array<string,mixed>|null $payload
     * @return array<mixed>
     */
    public function request(
        string $path,
        string $method = 'GET',
        ?array $payload = null,
        ?string $destination = null,
    ): array {
        $url = $this->origin . $path;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $headers = ['DPoP: ' . $this->proof($method, $url), 'Accept: application/json'];
            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            if ($this->token !== null) {
                $headers[] = 'Authorization: DPoP ' . $this->token;
            }
            $context = stream_context_create([
                'ssl' => [
                    'cafile' => $this->ca,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $payload === null ? '' : json_encode(
                        $payload === [] ? new \stdClass() : $payload,
                        JSON_THROW_ON_ERROR,
                    ),
                    'timeout' => 180,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                ],
            ]);
            $response = @fopen($url, 'rb', false, $context);
            Support::check(
                $response !== false,
                $method . ' ' . $path . ' did not complete a verified HTTPS connection.',
            );
            try {
                $metadata = stream_get_meta_data($response);
                $responseHeaders = $metadata['wrapper_data'] ?? [];
                preg_match('/^HTTP\/\S+ (\d+)/', $responseHeaders[0] ?? '', $statusMatch);
                $status = (int) ($statusMatch[1] ?? 0);
                $parsed = [];
                foreach ($responseHeaders as $header) {
                    if (str_contains($header, ':')) {
                        [$name, $value] = explode(':', $header, 2);
                        $parsed[strtolower($name)] = trim($value);
                    }
                }
                $this->nonce = $parsed['dpop-nonce'] ?? $this->nonce;
                if ($status >= 400) {
                    $detail = (string) fread($response, 4096);
                    if (
                        isset($parsed['dpop-nonce'])
                        && $attempt < 2
                        && (str_contains($detail, 'use_dpop_nonce') || str_contains($detail, 'invalid_dpop_proof'))
                    ) {
                        continue;
                    }
                    throw new \RuntimeException($method . ' ' . $path . ' returned HTTP ' . $status . ': ' . $detail);
                }
                Support::check($status >= 200 && $status < 300, 'Unexpected HTTP status ' . $status . '.');
                if ($destination === null) {
                    return [
                        $status,
                        json_decode(
                            (string) stream_get_contents($response),
                            true,
                            flags: JSON_THROW_ON_ERROR,
                        ),
                    ];
                }
                $output = fopen($destination, 'wb');
                Support::check($output !== false, 'Cannot write playback evidence.');
                $digest = hash_init('sha256');
                $length = 0;
                try {
                    while (!feof($response)) {
                        $chunk = fread($response, 65536);
                        Support::check(
                            $chunk !== false && !stream_get_meta_data($response)['timed_out'],
                            'Audio response timed out.',
                        );
                        Support::check(
                            fwrite($output, $chunk) === strlen($chunk),
                            'Cannot write complete playback evidence.',
                        );
                        hash_update($digest, $chunk);
                        $length += strlen($chunk);
                    }
                } finally {
                    fclose($output);
                }
                $declared = $parsed['content-length'] ?? null;
                Support::check(
                    $declared === null || $length === (int) $declared,
                    'Audio response ended before Content-Length.',
                );
                return [
                    'status' => $status,
                    'bytes' => $length,
                    'sha256' => hash_final($digest),
                    'contentType' => $parsed['content-type'] ?? null,
                    'contentLength' => $declared,
                    'readToEof' => true,
                ];
            } finally {
                fclose($response);
            }
        }
        throw new \RuntimeException('DPoP nonce negotiation did not complete.');
    }
}
