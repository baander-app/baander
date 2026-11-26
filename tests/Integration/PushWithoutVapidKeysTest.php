<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Notification\Application\Port\PushDeliveryPortInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * VAPID keys are optional configuration. Without them the push adapter must still build and
 * report push as unconfigured, or every push message is retried into the failure transport.
 */
final class PushWithoutVapidKeysTest extends KernelTestCase
{
    private const array KEYS = ['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY'];

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $name) {
            $this->saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = '';
            $_SERVER[$name] = '';
            putenv($name . '=');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->saved as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            putenv($process === false ? $name : $name . '=' . $process);
        }
    }

    public function testThePushAdapterBuildsWithoutAKeyPairAndReportsPushAsUnconfigured(): void
    {
        self::bootKernel();
        $delivery = self::getContainer()->get(PushDeliveryPortInterface::class);

        self::assertInstanceOf(PushDeliveryPortInterface::class, $delivery);
        self::assertFalse($delivery->isConfigured());
    }
}
