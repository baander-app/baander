<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Recommendation\Application\Query\GetRecommendationsForUserQuery;
use App\Recommendation\Interface\Controller\RecommendationController;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class RecommendationControllerTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function unsupportedPrincipals(): iterable
    {
        foreach (['index', 'forYou', 'store'] as $action) {
            yield $action . ' anonymous' => [$action, false];
            yield $action . ' without application identity' => [$action, true];
        }
    }

    #[DataProvider('unsupportedPrincipals')]
    public function testRejectsUnsupportedPrincipal(string $action, bool $authenticated): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticated ? $this->createStub(UserInterface::class) : null);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = new RecommendationController($security, $bus, new JsonEncoder());

        $response = $controller->{$action}(new Request());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testQueriesUsingApplicationIdentity(): void
    {
        $userId = Uuid::v7();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($userId->toString(), 'listener@baander.app', 'hash'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with(self::callback(static fn (GetRecommendationsForUserQuery $query): bool => $query->getUserId()->equals($userId)))
            ->willReturnCallback(static fn (object $query): Envelope => new Envelope($query, [new HandledStamp([], 'recommendations')]));
        $controller = new RecommendationController($security, $bus, new JsonEncoder());

        $response = $controller->index(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":[]}', $response->getContent());
    }
}
