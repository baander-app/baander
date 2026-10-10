<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\User;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * API messages follow each request's language: the signed-in user's saved choice, else
 * Accept-Language, else English. The worker's shared translator keeps English throughout,
 * so requests that interleave never see each other's language.
 */
final class ApiLanguageTest extends TestCase
{
    public function testInterleavedRequestsOfADanishAndAThaiUserEachGetTheirOwnLanguage(): void
    {
        $danish = $this->userWithLanguage('da');
        $thai = $this->userWithLanguage('th');
        $missing = Uuid::v7()->toString();
        $kernel = $this->client->getKernel();
        $translator = $this->translator();

        // Each request pauses once its controller is known, as a coroutine does on I/O.
        $pause = static function (ControllerEvent $event): void {
            if ($event->isMainRequest() && \Fiber::getCurrent() !== null) {
                \Fiber::suspend();
            }
        };
        $events = static::getContainer()->get(EventDispatcherInterface::class);
        $events->addListener(KernelEvents::CONTROLLER, $pause);
        try {
            $first = new \Fiber(fn (): Response => $kernel->handle($this->request('/api/libraries/' . $missing, $danish)));
            $second = new \Fiber(fn (): Response => $kernel->handle($this->request('/api/libraries/' . $missing, $thai)));
            $first->start();
            $second->start();
            self::assertTrue($first->isSuspended() && $second->isSuspended());
            self::assertSame('en', $translator->getLocale());

            $first->resume();
            $second->resume();
        } finally {
            $events->removeListener(KernelEvents::CONTROLLER, $pause);
        }

        self::assertSame(sprintf('Biblioteket "%s" blev ikke fundet.', $missing), $this->assertJsonResponse($first->getReturn(), 404)['error']['message']);
        self::assertSame(sprintf('ไม่พบไลบรารี "%s"', $missing), $this->assertJsonResponse($second->getReturn(), 404)['error']['message']);
        self::assertSame('en', $translator->getLocale());
    }

    public function testARequestWithoutATokenIsRefusedInTheAcceptLanguage(): void
    {
        $this->client->request('GET', '/api/libraries', server: ['HTTP_ACCEPT_LANGUAGE' => 'da-DK,da;q=0.9']);

        self::assertSame(
            ['error' => ['message' => 'Godkendelse påkrævet.', 'code' => 401]],
            $this->assertJsonResponse($this->client->getResponse(), 401),
        );
        self::assertSame('en', $this->translator()->getLocale());
    }

    public function testAControllersOwnMessageFollowsTheAcceptLanguage(): void
    {
        $this->client->request('POST', '/api/auth/password/reset-request', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'th',
            'REMOTE_ADDR' => '198.51.100.' . random_int(1, 254),
        ], content: json_encode(['email' => 'nobody-' . bin2hex(random_bytes(4)) . '@baander.app'], JSON_THROW_ON_ERROR));

        self::assertSame(
            'หากมีอีเมลนี้ในระบบ เราได้ส่งลิงก์รีเซ็ตรหัสผ่านให้แล้ว',
            $this->assertJsonResponse($this->client->getResponse(), 200, 'data')['data']['message'],
        );
    }

    public function testADanishUserRefusedOnAnAdminRouteIsToldInDanish(): void
    {
        $response = $this->send('GET', '/api/admin/users', $this->userWithLanguage('da'));

        self::assertSame(['error' => ['message' => 'Forbudt.', 'code' => 403]], $this->assertJsonResponse($response, 403));
        self::assertSame('en', $this->translator()->getLocale());
    }

    public function testADanishUsersInvalidRequestIsReportedInDanish(): void
    {
        $admin = $this->createAdminUser();
        $this->settings()->set($admin->getId()->toString(), 'language', 'da');

        $response = $this->send('POST', '/api/libraries', $admin, ['name' => '', 'path' => '', 'type' => '']);

        self::assertSame('Validering mislykkedes.', $this->assertJsonResponse($response, 422)['error']['message']);
        self::assertSame('en', $this->translator()->getLocale());
    }

    private function userWithLanguage(string $language): User
    {
        $user = $this->createTestUser();
        $this->settings()->set($user->getId()->toString(), 'language', $language);

        return $user;
    }

    /** @param array<string, mixed> $content */
    private function send(string $method, string $uri, User $user, array $content = []): Response
    {
        $this->client->request($method, $uri, server: $this->server($user), content: $content === [] ? null : json_encode($content, JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function request(string $uri, User $user): Request
    {
        return Request::create($uri, 'GET', server: $this->server($user));
    }

    /** @return array<string, string> */
    private function server(User $user): array
    {
        return ['CONTENT_TYPE' => 'application/json', BaanderHeader::TestUserId->serverKey() => $user->getId()->toString()];
    }

    private function settings(): UserSettingsContractInterface
    {
        $settings = static::getContainer()->get(UserSettingsContractInterface::class);
        self::assertInstanceOf(UserSettingsContractInterface::class, $settings);

        return $settings;
    }

    private function translator(): LocaleAwareInterface
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);

        return $translator;
    }
}
