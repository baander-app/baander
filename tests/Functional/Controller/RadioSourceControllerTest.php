<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RadioSourceControllerTest extends TestCase
{
    public function testCreateIsForbiddenForNonAdmin(): void
    {
        $name = 'Listener source ' . bin2hex(random_bytes(4));

        $response = $this->authenticatedRequest('POST', '/api/radio/sources', $this->createTestUser(), [
            'name' => $name,
            'type' => 'iprd',
            'syncUrl' => 'https://radio.baander.app/catalog',
        ]);

        $this->assertJsonResponse($response, 403);
        self::assertNotContains($name, array_column($this->sources()->listSources(administrator: true), 'name'));
    }

    public function testAdminCreatesSource(): void
    {
        $name = 'Admin source ' . bin2hex(random_bytes(4));

        $response = $this->authenticatedRequest('POST', '/api/radio/sources', $this->createAdminUser(), [
            'name' => $name,
            'type' => 'iprd',
            'syncUrl' => 'https://radio.baander.app/catalog',
        ]);

        $data = $this->assertJsonResponse($response, 201);
        self::assertSame($name, $data['data']['name']);
        self::assertContains($name, array_column($this->sources()->listSources(administrator: true), 'name'));
    }

    public function testAMemberListsSourcesWithoutTheirSyncConfiguration(): void
    {
        $name = 'Projected source ' . bin2hex(random_bytes(4));
        $this->sources()->createSource($name, 'iprd', 'https://radio.baander.app/private-catalog', ['token' => 'operator-only'], '0 */6 * * *');

        $member = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/radio/sources', $this->createTestUser()), 200, 'data');
        self::assertNotSame([], $member['data']);
        foreach ($member['data'] as $source) {
            self::assertArrayNotHasKey('syncUrl', $source);
            self::assertArrayNotHasKey('syncConfig', $source);
        }
        self::assertSame('0 */6 * * *', self::named($member['data'], $name)['syncSchedule']);

        $admin = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/radio/sources', $this->createAdminUser()), 200, 'data');
        $source = self::named($admin['data'], $name);
        self::assertSame('https://radio.baander.app/private-catalog', $source['syncUrl']);
        self::assertSame(['token' => 'operator-only'], $source['syncConfig']);
    }

    public function testTheConsoleCreateOutputEqualsTheApiCreateAnswer(): void
    {
        $fields = [
            'type' => 'iprd',
            'syncUrl' => 'https://radio.baander.app/catalog',
            'syncConfig' => ['region' => 'eu'],
            'syncSchedule' => '0 */6 * * *',
        ];
        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/radio/sources', $this->createAdminUser(), ['name' => 'API source'] + $fields),
            201,
            'data',
        )['data'];

        $tester = new CommandTester((new Application(self::$kernel))->find('app:radio:source:create'));
        $exitCode = $tester->execute([
            '--name' => 'Console source',
            '--type' => $fields['type'],
            '--sync-url' => $fields['syncUrl'],
            '--sync-config' => json_encode($fields['syncConfig'], JSON_THROW_ON_ERROR),
            '--sync-schedule' => $fields['syncSchedule'],
            '--json' => true,
        ]);
        self::assertSame(Command::SUCCESS, $exitCode);
        $console = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(array_keys($api), array_keys($console));
        $comparable = static fn (array $source): array => array_diff_key($source, array_flip(['publicId', 'name', 'createdAt', 'updatedAt']));
        self::assertSame($comparable($api), $comparable($console));
        self::assertSame($fields['syncUrl'], $console['syncUrl']);
    }

    /**
     * @param list<array<string, mixed>> $sources
     *
     * @return array<string, mixed>
     */
    private static function named(array $sources, string $name): array
    {
        $matches = array_values(array_filter($sources, static fn (array $source): bool => $source['name'] === $name));
        self::assertCount(1, $matches);

        return $matches[0];
    }

    private function sources(): RadioSourcePortInterface
    {
        return static::getContainer()->get(RadioSourcePortInterface::class);
    }
}
