<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Application\Port\ServerDrainStateInterface;
use App\Shared\Infrastructure\Swoole\Drain\DrainingResponseListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DrainingResponseListenerTest extends TestCase
{
    public function testWhileTheServerDrainsAResponseClosesItsConnection(): void
    {
        $event = $this->event(HttpKernelInterface::MAIN_REQUEST);

        $this->listener(true)($event);

        self::assertSame('close', $event->getResponse()->headers->get('Connection'));
    }

    public function testOutsideADrainTheConnectionHeaderIsLeftAlone(): void
    {
        $event = $this->event(HttpKernelInterface::MAIN_REQUEST);

        $this->listener(false)($event);

        self::assertFalse($event->getResponse()->headers->has('Connection'));
    }

    public function testASubRequestResponseIsLeftAlone(): void
    {
        $event = $this->event(HttpKernelInterface::SUB_REQUEST);

        $this->listener(true)($event);

        self::assertFalse($event->getResponse()->headers->has('Connection'));
    }

    private function listener(bool $draining): DrainingResponseListener
    {
        $drain = $this->createStub(ServerDrainStateInterface::class);
        $drain->method('isDraining')->willReturn($draining);

        return new DrainingResponseListener($drain);
    }

    private function event(int $requestType): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('https://baander.app/ready'), $requestType, new Response());
    }
}
