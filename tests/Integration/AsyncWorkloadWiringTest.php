<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog;
use App\Shared\Infrastructure\Messenger\ValidatedSendersLocator;
use App\Tests\Fixtures\Messaging\FailureTransportProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class AsyncWorkloadWiringTest extends KernelTestCase
{
    public function testFrameworkCompilationPreservesTheValidatedCatalogAndDecorator(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $catalog = $container->get(AsyncWorkloadCatalog::class);
        self::assertCount(17, $catalog->workloads());
        self::assertCount(16, array_filter($catalog->workloads(), static fn (array $workload): bool => $workload['default']));
        self::assertSame(['async', 'scheduler'], $catalog->receivers());
        self::assertFalse($catalog->workloads()[FetchLyricsCommand::class]['default']);
        self::assertSame('async', $catalog->workloads()[FailureTransportProbe::class]['transport']);
        self::assertInstanceOf(ValidatedSendersLocator::class, $container->get('messenger.senders_locator'));
    }

    public function testEffectiveSenderOverrideIsRejectedByTheCompiledApplication(): void
    {
        self::bootKernel();
        $senders = self::getContainer()->get('messenger.senders_locator');
        $this->expectException(\LogicException::class);
        iterator_to_array($senders->getSenders(new Envelope(new \stdClass(), [new TransportNamesStamp(['async'])])));
    }
}
