<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Mime;

use Egulias\EmailValidator\EmailValidator;
use ReflectionProperty;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;
use Symfony\Component\Mime\Address;

final readonly class MimeAddressValidatorInstaller implements Bootable
{
    public function __construct(private EmailValidator $validator) {}

    public function boot(array $runtimeConfiguration = []): void
    {
        // Address has no injection API; install the contextual service before workers start validating.
        $property = new ReflectionProperty(Address::class, 'validator');

        if ($property->isInitialized() && $property->getValue() === $this->validator) {
            return;
        }

        $property->setValue(null, $this->validator);
    }
}
