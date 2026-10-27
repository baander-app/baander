<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\RegistryTests;

final class Tls
{
    /**
     * @param list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    public static function command(array $command): array
    {
        $result = Runtime::run($command);
        Runtime::requireThat($result['code'] === 0, 'Certificate command failed: ' . $result['stderr']);
        return $result;
    }
    public static function authority(string $directory): void
    {
        self::command([
            'openssl',
            'req',
            '-x509',
            '-newkey',
            'rsa:2048',
            '-nodes',
            '-keyout',
            $directory . '/ca.key',
            '-out',
            $directory . '/ca.crt',
            '-days',
            '1',
            '-addext',
            'basicConstraints=critical,CA:TRUE',
            '-addext',
            'keyUsage=critical,keyCertSign,cRLSign',
            '-subj',
            '/CN=Baander disposable CA',
        ]);
    }
    /**
     * @return array{string,string}
     */
    public static function certificate(string $directory, string $name, ?string $hostname = null, bool $expired = false): array
    {
        $key = $directory . '/' . $name . '.key';
        $csr = $directory . '/' . $name . '.csr';
        $crt = $directory . '/' . $name . '.crt';
        self::command([
            'openssl',
            'req',
            '-new',
            '-newkey',
            'rsa:2048',
            '-nodes',
            '-keyout',
            $key,
            '-out',
            $csr,
            '-subj',
            '/CN=' . ($hostname ?? $name),
        ]);
        file_put_contents(
            $directory . '/' . $name . '.ext',
            $hostname === null ? "extendedKeyUsage=clientAuth\n" : "subjectAltName=DNS:" . $hostname . "\nextendedKeyUsage=serverAuth\n",
        );
        self::command([
            'openssl',
            'x509',
            '-req',
            '-in',
            $csr,
            '-CA',
            $directory . '/ca.crt',
            '-CAkey',
            $directory . '/ca.key',
            '-CAcreateserial',
            '-out',
            $crt,
            '-days',
            '1',
            ...$expired ? ['-not_before', '20000101000000Z', '-not_after', '20010101000000Z'] : [],
            '-extfile',
            $directory . '/' . $name . '.ext',
        ]);
        return [$key, $crt];
    }
}
