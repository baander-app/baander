<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;

/** ES256 platform authenticator that produces real WebAuthn assertions for one registered credential. */
final class WebAuthnTestCredential
{
    private string $privateKey;
    private string $x;
    private string $y;
    private string $credentialId;
    private int $counter = 0;

    public function __construct(private readonly string $userId, private readonly string $rpId)
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Unable to create the WebAuthn test key.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)) {
            throw new RuntimeException('Unable to read the WebAuthn test key.');
        }
        $this->privateKey = $privateKey;
        $this->x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->credentialId = random_bytes(32);
    }

    /** The registered passkey, stored in the shape PasskeyService::credentialRecordFromArray() reads. */
    public function passkey(): Passkey
    {
        return Passkey::create(Uuid::v7(), 'Acceptance passkey', self::encode($this->credentialId), [
            'publicKeyCredentialId' => base64_encode($this->credentialId),
            'type' => 'public-key',
            'transports' => ['internal'],
            'attestationType' => 'none',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'credentialPublicKey' => base64_encode($this->coseKey()),
            'userHandle' => $this->userId,
        ], $this->counter);
    }

    /**
     * Signs an assertion for the base64url challenge from the authentication options.
     *
     * @return array<string, mixed>
     */
    public function assert(string $challenge, string $origin): array
    {
        ++$this->counter;
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // User present and user verified, followed by the big-endian signature counter.
        $authenticatorData = hash('sha256', $this->rpId, true) . "\x05" . pack('N', $this->counter);
        if (!openssl_sign($authenticatorData . hash('sha256', $clientData, true), $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the WebAuthn assertion.');
        }
        $id = self::encode($this->credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::encode($clientData),
                'authenticatorData' => self::encode($authenticatorData),
                'signature' => self::encode($signature),
                'userHandle' => self::encode($this->userId),
            ],
        ];
    }

    /** CBOR map {kty: EC2, alg: ES256, crv: P-256, x, y} (RFC 9053 §7.1.1). */
    private function coseKey(): string
    {
        return "\xA5" . "\x01\x02" . "\x03\x26" . "\x20\x01" . "\x21\x58\x20" . $this->x . "\x22\x58\x20" . $this->y;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
