<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Infrastructure\Messaging;

use App\Session\Infrastructure\Messaging\ListeningSessionInteractionAdapter;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class ListeningSessionInteractionAdapterTest extends TestCase
{
    public function testCoordinationLeavesEndpointBindingToTheTransportFence(): void
    {
        $owner = new Uuid();
        $coordination = $this->createMock(\App\Session\Application\Port\PlaybackCoordinationInterface::class);
        $coordination->expects(self::once())->method('execute')->with($owner, \App\Session\Application\Port\PlaybackOperation::Snapshot, [], null)->willReturn(['revision' => 1]);
        $connections = $this->createMock(\App\Session\Application\Port\PlaybackConnectionsInterface::class);
        $connections->expects(self::never())->method('bind');
        $adapter = new ListeningSessionInteractionAdapter($coordination, $connections);
        self::assertSame(['revision' => 1], $adapter->coordinate($owner, 'snapshot', []));
    }

    public function testExplicitBindingAndDisconnectDelegateOnlyTheChosenConnection(): void
    {
        $owner = new Uuid();
        $incarnation = new Uuid()->toString();
        $connections = $this->createMock(\App\Session\Application\Port\PlaybackConnectionsInterface::class);
        $connections->expects(self::once())->method('bind')->with(7, $owner, $incarnation, 'private-capability');
        $connections->expects(self::once())->method('unbind')->with(7);
        $adapter = new ListeningSessionInteractionAdapter($this->createStub(\App\Session\Application\Port\PlaybackCoordinationInterface::class), $connections);
        $adapter->bindPlayback(7, $owner, $incarnation, 'private-capability');
        $adapter->disconnectPlayback(7);
    }

}
