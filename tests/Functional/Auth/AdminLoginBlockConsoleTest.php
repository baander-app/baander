<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The admin API and the app:login-block:* commands reach the same use cases and agree on the outcome. */
final class AdminLoginBlockConsoleTest extends TestCase
{
    private const string PATH = '/api/admin/login-blocks';

    public function testListShowsTheBlocksTheApiShowsInTheSameOrder(): void
    {
        foreach (['192.0.2.21', '192.0.2.22', '192.0.2.23'] as $ip) {
            $this->blocks()->save(LoginBlock::create($ip, 'bot@baander.app', 'filled', 'curl/8'));
        }

        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', self::PATH . '?limit=2&offset=1', $this->createAdminUser()),
            200,
            'data',
        )['data'];

        $list = $this->command('app:login-block:list');
        self::assertSame(Command::SUCCESS, $list->execute(['--limit' => '2', '--offset' => '1', '--json' => true]));
        self::assertCount(2, $api);
        self::assertSame($api, json_decode($list->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testDeleteAllWithForceRemovesEveryBlock(): void
    {
        $this->blocks()->save(LoginBlock::create('192.0.2.31', 'bot@baander.app', 'filled', 'curl/8'));
        $this->blocks()->save(LoginBlock::create('192.0.2.32', 'bot@baander.app', 'filled', 'curl/8'));

        $delete = $this->command('app:login-block:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['--all' => true, '--force' => true], ['interactive' => false]), $delete->getDisplay());

        self::assertSame(0, $this->blocks()->countRecent());
        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', self::PATH, $this->createAdminUser()), 200, 'data');
        self::assertSame([], $api['data']);
        self::assertSame(0, $api['meta']['total']);
    }

    public function testDeleteOneRemovesOnlyThatBlockOnBothPaths(): void
    {
        $viaApi = LoginBlock::create('192.0.2.41', 'bot@baander.app', 'filled', 'curl/8');
        $viaCli = LoginBlock::create('192.0.2.42', 'bot@baander.app', 'filled', 'curl/8');
        $kept = LoginBlock::create('192.0.2.43', 'bot@baander.app', 'filled', 'curl/8');
        foreach ([$viaApi, $viaCli, $kept] as $block) {
            $this->blocks()->save($block);
        }

        $response = $this->authenticatedRequest('DELETE', self::PATH . '/' . $viaApi->getId()->toString(), $this->createSuperAdminUser());
        self::assertSame(204, $response->getStatusCode());
        $delete = $this->command('app:login-block:delete');
        self::assertSame(Command::SUCCESS, $delete->execute(['id' => $viaCli->getId()->toString()], ['interactive' => false]), $delete->getDisplay());

        $ids = array_map(static fn (LoginBlock $block): string => $block->getId()->toString(), $this->blocks()->findRecent(100));
        self::assertNotContains($viaApi->getId()->toString(), $ids);
        self::assertNotContains($viaCli->getId()->toString(), $ids);
        self::assertContains($kept->getId()->toString(), $ids);
    }

    public function testAnUnknownIdIsNotFoundOnBothPaths(): void
    {
        $unknown = (new Uuid())->toString();

        $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', self::PATH . '/' . $unknown, $this->createSuperAdminUser()),
            404,
        );

        $delete = $this->command('app:login-block:delete');
        self::assertSame(Command::FAILURE, $delete->execute(['id' => $unknown], ['interactive' => false]));
        self::assertStringContainsString($unknown, $delete->getDisplay());
    }

    private function blocks(): LoginBlockRepositoryInterface
    {
        $blocks = static::getContainer()->get(LoginBlockRepositoryInterface::class);
        self::assertInstanceOf(LoginBlockRepositoryInterface::class, $blocks);

        return $blocks;
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
