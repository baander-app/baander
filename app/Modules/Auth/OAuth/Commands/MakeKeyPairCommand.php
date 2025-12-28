<?php

namespace App\Modules\Auth\OAuth\Commands;

use Illuminate\Console\Command;

class MakeKeyPairCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'oauth:keypair';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an RSA key pair for the auth server';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $config = [
            'private_key_type'      => OPENSSL_KEYTYPE_RSA,
            'private_key_bits'      => 3072,               // 128-bit equiv. strength
            'encrypt_key'           => true,
            'encrypt_key_cipher'    => OPENSSL_CIPHER_AES_256_CBC,
            'digest_alg'            => 'sha256',
            // 'config' => '/path/to/openssl.cnf',  // uncomment on Windows
        ];

        $res = openssl_pkey_new($config);
        if (!$res) throw new \RuntimeException(openssl_error_string());


        openssl_pkey_export($res, $privatePem, config('oauth.encryption_key'), $config);
        $publicPem = openssl_pkey_get_details($res)['key'];

        \File::put(storage_path('oauth-private.key'), $privatePem);
        \File::put(storage_path('oauth-public.key'), $publicPem);
        chmod(storage_path('oauth-private.key'), 0660);
        chmod(storage_path('oauth-public.key'), 0660);

        $this->info('Wrote public and private keys to storage.');
    }
}
