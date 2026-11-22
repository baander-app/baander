<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Interface\Controller;

use App\Tests\Functional\TestCase;

/**
 * The SPA page names the API origin the web app talks to: the origin the page was requested
 * on when it is APP_URL's or one of AUTH_EXTRA_ORIGINS (.env.test lists https://baander.app),
 * and APP_URL's origin for any other Host.
 */
final class SpaControllerTest extends TestCase
{
    public function testAnUnlistedHostRendersTheApiUrlOfAppUrl(): void
    {
        $appOrigin = rtrim((string) ($_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? ''), '/');

        self::assertNotSame('', $appOrigin);
        self::assertSame($appOrigin, $this->renderedApiUrl('https://other.baander.app/'));
    }

    public function testAListedExtraOriginRendersItselfAsTheApiUrl(): void
    {
        self::assertSame('https://baander.app', $this->renderedApiUrl('https://baander.app/library'));
    }

    private function renderedApiUrl(string $url): string
    {
        $this->client->request('GET', $url);
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, preg_match('/window\.__BAANDER_API_URL__ = (.+);/', (string) $response->getContent(), $match));

        return json_decode($match[1], flags: JSON_THROW_ON_ERROR);
    }
}
