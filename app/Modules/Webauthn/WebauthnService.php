<?php

namespace App\Modules\Webauthn;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;

class WebauthnService
{
    public function __construct(
        private readonly AttestationStatementSupportManager $attestationStatementSupportManager,
        private readonly SerializerInterface                $serializer,
    )
    {
    }

    public function serialize(mixed $value)
    {
        return $this->serializer->serialize($value, 'json');
    }

    public function deserialize(string $value, string $targetClass)
    {
        return $this->serializer->deserialize($value, $targetClass, 'json');
    }
}