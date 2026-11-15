<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\Service\MusicBrainzMatch;

use App\Catalog\Application\Service\MusicBrainzMatch\ReleaseSearchTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseSearchTitleTest extends TestCase
{
    /** @return iterable<string, array{string, string}> the title and its shortened form */
    public static function titlesEndingInACommaSeparatedList(): iterable
    {
        yield 'label, catalogue number and country' => ['Ten Thousand Fists [Reprise Records,9362-49433-2,EU]', 'Ten Thousand Fists'];
        yield 'after other bracketed text' => ['Album (Deluxe Edition) [Label, CAT-1, EU]', 'Album (Deluxe Edition)'];
    }

    #[DataProvider('titlesEndingInACommaSeparatedList')]
    public function testACommaSeparatedListInSquareBracketsAtTheEndIsCutWithTheSpaceBeforeIt(string $title, string $shortened): void
    {
        self::assertSame($shortened, ReleaseSearchTitle::shortened($title));
    }

    /** @return iterable<string, array{string}> */
    public static function titlesThatStay(): iterable
    {
        yield 'square brackets without a comma' => ['Album [FLAC]'];
        yield 'an edition in square brackets' => ['Album [Instrumentals]'];
        yield 'a list in curly brackets' => ['Album {Label,CAT-1,EU}'];
        yield 'a list that is not at the end' => ['Album [Label,CAT-1,EU] (Live)'];
        yield 'round brackets at the start' => ['(What\'s the Story) Morning Glory?'];
        yield 'nothing but the list' => ['[a,b]'];
    }

    #[DataProvider('titlesThatStay')]
    public function testAnyOtherTitleStaysAsItIs(string $title): void
    {
        self::assertSame($title, ReleaseSearchTitle::shortened($title));
    }
}
