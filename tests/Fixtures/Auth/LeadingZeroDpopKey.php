<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

/** Public disposable key fixture whose P-256 coordinate requires a leading zero byte. */
final class LeadingZeroDpopKey
{
    public const string PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgalaBxX7jfs/UD+sE
vXHq9i8OBvQ1DBArxPLV1MDOoUGhRANCAASx2Np+zAHEnFAQcAz5tFe9KufMsMUO
WFlexMMsU6f9kgCd8H5caC57ouV4SnauTekbXWnePrxrlwEO5mepbkZb
-----END PRIVATE KEY-----
PEM;
}
