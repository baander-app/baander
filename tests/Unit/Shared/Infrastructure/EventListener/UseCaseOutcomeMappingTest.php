<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Infrastructure\EventListener\ExceptionSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use stdClass;
use Throwable;

/** The shared use case outcomes reach the HTTP client as 404, 409 and 422 with the shared error envelope. */
final class UseCaseOutcomeMappingTest extends TestCase
{
    /** @return iterable<string, array{Throwable, int}> */
    public static function outcomes(): iterable
    {
        yield 'not found' => [new NotFoundException('No library has the ID "music".'), Response::HTTP_NOT_FOUND];
        yield 'conflict' => [new ConflictException('A scan of "Music" is already running.'), Response::HTTP_CONFLICT];
        yield 'invalid input' => [new InvalidInputException('The cron expression "* *" is not valid.'), Response::HTTP_UNPROCESSABLE_ENTITY];
    }

    #[DataProvider('outcomes')]
    public function testAnOutcomeThrownByAControllerGetsItsStatusAndTheSharedErrorEnvelope(Throwable $outcome, int $status): void
    {
        $response = $this->handle(static function () use ($outcome): never {
            throw $outcome;
        });

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => $outcome->getMessage(), 'code' => $status]], $this->body($response));
    }

    public function testAConflictFromAHandlerBehindTheBusIsA409NotA500(): void
    {
        $bus = $this->bus(static function (): never {
            throw new ConflictException('A scan of "Music" is already running.', ['reason' => 'scan_running']);
        });

        $response = $this->handle($this->dispatching($bus));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'A scan of "Music" is already running.', 'code' => 409, 'details' => ['reason' => 'scan_running']]],
            $this->body($response),
        );
    }

    public function testAnOutcomeFromANestedDispatchIsUnwrappedThroughEveryHandlerFailure(): void
    {
        $inner = $this->bus(static function (): never {
            throw new NotFoundException('No library has the ID "music".');
        });
        $outer = $this->bus(static fn (): mixed => $inner->dispatch(new stdClass()));

        $response = $this->handle($this->dispatching($outer));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(['error' => ['message' => 'No library has the ID "music".', 'code' => 404]], $this->body($response));
    }

    public function testAnHttpExceptionFromAHandlerIsPassedOnUnwrappedForTheFrameworkToRender(): void
    {
        $bus = $this->bus(static function (): never {
            throw new NotFoundHttpException('No such page.');
        });

        $this->expectException(NotFoundHttpException::class);

        $this->handle($this->dispatching($bus));
    }

    private function handle(callable $controller): Response
    {
        $events = new EventDispatcher();
        $events->addListener(KernelEvents::EXCEPTION, new ExceptionSubscriber(new NullLogger()));
        $controllers = new class ($controller) implements ControllerResolverInterface {
            /** @param callable $controller */
            public function __construct(private $controller)
            {
            }

            public function getController(Request $request): callable
            {
                return $this->controller;
            }
        };
        $arguments = new class implements ArgumentResolverInterface {
            /** @return array<mixed> */
            public function getArguments(Request $request, callable $controller, ?\ReflectionFunctionAbstract $reflector = null): array
            {
                return [];
            }
        };

        return (new HttpKernel($events, $controllers, new RequestStack(), $arguments))
            ->handle(Request::create('/api/admin/libraries/music/scan', 'POST'), HttpKernelInterface::MAIN_REQUEST, true);
    }

    /** A controller that dispatches a message through the bus, as the admin controllers do. */
    private function dispatching(MessageBusInterface $bus): callable
    {
        return static function () use ($bus): Response {
            $bus->dispatch(new stdClass());

            return new Response();
        };
    }

    private function bus(callable $handler): MessageBusInterface
    {
        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([stdClass::class => [$handler]]))]);
    }

    /** @return array<mixed> */
    private function body(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return $body;
    }
}
