<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Model;

use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LibraryProviderOrderTest extends TestCase
{
    public function testKeepsTheChosenOrderAndLibrary(): void
    {
        $libraryId = Uuid::v7();
        $order = LibraryProviderOrder::fromProviders($libraryId, ['tmdb', 'local', 'tvdb'], ['tvdb', 'tmdb', 'local']);

        self::assertTrue($libraryId->equals($order->getLibraryId()));
        self::assertSame(['tmdb', 'local', 'tvdb'], $order->getProviderIds());
    }

    /** @return iterable<string, array{array<array-key, string>}> */
    public static function invalidOrders(): iterable
    {
        yield 'missing provider' => [['tvdb', 'tmdb']];
        yield 'duplicate provider' => [['tvdb', 'tmdb', 'tmdb']];
        yield 'unknown provider' => [['tvdb', 'tmdb', 'discogs']];
        yield 'extra provider' => [['tvdb', 'tmdb', 'local', 'discogs']];
        yield 'empty order' => [[]];
        yield 'object keys' => [['first' => 'tvdb', 'second' => 'tmdb', 'third' => 'local']];
    }

    /** @param array<array-key, string> $providers */
    #[DataProvider('invalidOrders')]
    public function testRequiresEverySupportedProviderExactlyOnce(array $providers): void
    {
        $this->expectException(InvalidArgumentException::class);

        LibraryProviderOrder::fromProviders(Uuid::v7(), $providers, ['tvdb', 'tmdb', 'local']);
    }

    public function testTvdbCannotBeAddedToAMovieLibraryOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LibraryProviderOrder::fromProviders(Uuid::v7(), ['tvdb', 'tmdb', 'local'], ['tmdb', 'local']);
    }
}
