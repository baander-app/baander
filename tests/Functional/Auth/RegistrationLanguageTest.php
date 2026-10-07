<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\UserPreference\Application\Port\UserSettingStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Registration stores the browser's language as the new user's email language,
 * but only when it differs from the server default.
 */
final class RegistrationLanguageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function browsers(): iterable
    {
        yield 'danish browser, english server' => ['en', 'da-DK,da;q=0.9,en;q=0.5', 'da'];
        yield 'english browser, english server' => ['en', 'en-US,en;q=0.9', null];
        yield 'unsupported first choice whose fallback is the server default' => ['en', 'de-DE,en;q=0.5', null];
        yield 'danish browser, danish server' => ['da', 'da-DK', null];
        yield 'english browser, danish server' => ['da', 'en-GB', 'en'];
        yield 'wildcard only' => ['en', '*', null];
        yield 'no header' => ['en', null, null];
    }

    #[DataProvider('browsers')]
    public function testStoresTheBrowserLanguageOnlyWhenItDiffersFromTheServerDefault(string $serverDefault, ?string $acceptLanguage, ?string $stored): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save(['i18n.default_language' => $serverDefault]);
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '198.51.100.' . random_int(1, 254)];
        if ($acceptLanguage !== null) {
            $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
        }

        $this->client->request('POST', '/api/auth/register', [], [], $server, json_encode([
            'email' => 'new-' . bin2hex(random_bytes(4)) . '@baander.app',
            'name' => 'New Listener',
            'password' => 'Test-password-42',
        ], JSON_THROW_ON_ERROR));
        $body = $this->assertJsonResponse($this->client->getResponse(), 201, 'data');

        $userId = Uuid::fromString((string) $body['data']['uuid']);
        $this->assertSame($stored, static::getContainer()->get(UserSettingStoreInterface::class)->find($userId, 'language'));
    }
}
