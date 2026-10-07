<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OAuthPurgeCodesCommandTest extends TestCase
{
    public function testDeletesCodesExpiredForMoreThanAnHourThroughTheCommandBus(): void
    {
        $user = $this->createTestUser();
        $client = $this->insertClient();
        $staleAuthCode = $this->insertAuthCode($client, $user->getId(), '-2 hours');
        $freshAuthCode = $this->insertAuthCode($client, $user->getId(), '+10 minutes');
        $staleDeviceCode = $this->insertDeviceCode($client, '-2 hours');
        $graceDeviceCode = $this->insertDeviceCode($client, '-30 minutes');

        $tester = new CommandTester((new Application($this->client->getKernel()))->find('app:oauth:purge-codes'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertMatchesRegularExpression(
            '/Deleted \d+ authorization code\(s\) and \d+ device code\(s\) that expired before \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\./',
            preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '',
        );
        self::assertFalse($this->exists('oauth_auth_codes', $staleAuthCode));
        self::assertTrue($this->exists('oauth_auth_codes', $freshAuthCode));
        self::assertFalse($this->exists('oauth_device_codes', $staleDeviceCode));
        self::assertTrue($this->exists('oauth_device_codes', $graceDeviceCode), 'A polling device still learns that its code expired.');
    }

    private function insertClient(): Uuid
    {
        $id = Uuid::generate();
        $this->entityManager->getConnection()->insert('oauth_clients', [
            'id' => $id->toString(),
            'public_id' => (new PublicId())->toString(),
            'name' => 'Purge client',
            'redirect' => '["https://app.baander.app/callback"]',
            'device_client' => 'true',
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $id;
    }

    private function insertAuthCode(Uuid $client, Uuid $user, string $expiresIn): Uuid
    {
        $id = Uuid::generate();
        $this->entityManager->getConnection()->insert('oauth_auth_codes', [
            'id' => $id->toString(),
            'code_id' => bin2hex(random_bytes(16)),
            'client_id' => $client->toString(),
            'user_id' => $user->toString(),
            'redirect_uri' => 'https://app.baander.app/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'expires_at' => (new \DateTimeImmutable($expiresIn))->format('Y-m-d H:i:s.uP'),
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $id;
    }

    private function insertDeviceCode(Uuid $client, string $expiresIn): Uuid
    {
        $id = Uuid::generate();
        $this->entityManager->getConnection()->insert('oauth_device_codes', [
            'id' => $id->toString(),
            'device_code' => bin2hex(random_bytes(16)),
            'user_code' => strtoupper(bin2hex(random_bytes(6))),
            'client_id' => $client->toString(),
            'verification_uri' => 'https://app.baander.app/device',
            'expires_at' => (new \DateTimeImmutable($expiresIn))->format('Y-m-d H:i:s.uP'),
            'created_at' => '2026-10-07 12:00:00+00',
            'updated_at' => '2026-10-07 12:00:00+00',
        ]);

        return $id;
    }

    private function exists(string $table, Uuid $id): bool
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM ' . $table . ' WHERE id = ?', [$id->toString()]) !== false;
    }
}
