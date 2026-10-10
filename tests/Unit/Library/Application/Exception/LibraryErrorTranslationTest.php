<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\Exception;

use App\Library\Application\Exception\InvalidLibraryTypeException;
use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Exception\LibraryMediaFilesAllMissingException;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryRootOverlapsException;
use App\Library\Application\Exception\LibrarySlugTakenException;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The Library outcome exceptions keep an English message for the console and logs, and the
 * HTTP error response translates them from the `library` catalog into the request locale.
 */
final class LibraryErrorTranslationTest extends TestCase
{
    /** @return iterable<string, array{\Throwable&TranslatableInterface}> */
    public static function exceptions(): iterable
    {
        yield 'slug taken' => [LibrarySlugTakenException::forSlug('jazz')];
        yield 'not found' => [LibraryNotFoundException::forIdentifier('jazz')];
        yield 'scan running' => [LibraryBusyException::heldBy('Jazz', LibraryClaimKind::Scan)];
        yield 'delete running' => [LibraryBusyException::heldBy('Jazz', LibraryClaimKind::Delete)];
        yield 'invalid type' => [InvalidLibraryTypeException::forType('vinyl')];
        yield 'root overlaps' => [LibraryRootOverlapsException::with('/data/music/jazz', 'music', '/data/music')];
        yield 'all files missing' => [LibraryMediaFilesAllMissingException::underRoot('/data/music')];
    }

    #[DataProvider('exceptions')]
    public function testTheEnglishCatalogSaysWhatTheConsoleSays(\Throwable&TranslatableInterface $exception): void
    {
        self::assertSame($exception->getMessage(), $exception->trans(self::translator(), 'en'));
    }

    /** @return iterable<string, array{\Throwable&TranslatableInterface, string, string}> */
    public static function translations(): iterable
    {
        yield 'slug taken, da' => [LibrarySlugTakenException::forSlug('jazz'), 'da', 'Et bibliotek med slug "jazz" findes allerede.'];
        yield 'not found, da' => [LibraryNotFoundException::forIdentifier('jazz'), 'da', 'Biblioteket "jazz" blev ikke fundet.'];
        yield 'scan running, da' => [LibraryBusyException::heldBy('Jazz', LibraryClaimKind::Scan), 'da', 'En scanning er allerede i gang for biblioteket "Jazz".'];
        yield 'delete running, da' => [LibraryBusyException::heldBy('Jazz', LibraryClaimKind::Delete), 'da', 'En sletning med filer er i gang for biblioteket "Jazz".'];
        yield 'invalid type, da' => [InvalidLibraryTypeException::forType('vinyl'), 'da', 'Ugyldig bibliotekstype "vinyl". Tilladte: music, podcast, audiobook, movie, tv_show.'];
        yield 'slug taken, th' => [LibrarySlugTakenException::forSlug('jazz'), 'th', 'มีไลบรารีที่ใช้ slug "jazz" อยู่แล้ว'];
        yield 'not found, th' => [LibraryNotFoundException::forIdentifier('jazz'), 'th', 'ไม่พบไลบรารี "jazz"'];
        yield 'scan running, th' => [LibraryBusyException::heldBy('Jazz', LibraryClaimKind::Scan), 'th', 'มีการสแกนอยู่แล้วสำหรับไลบรารี "Jazz"'];
        yield 'delete running, th' => [LibraryBusyException::heldBy('Jazz', LibraryClaimKind::Delete), 'th', 'มีการลบพร้อมไฟล์กำลังดำเนินการอยู่สำหรับไลบรารี "Jazz"'];
        yield 'root overlaps, da' => [LibraryRootOverlapsException::with('/data/music/jazz', 'music', '/data/music'), 'da', 'Stien "/data/music/jazz" ligger inden i eller indeholder roden "/data/music" for biblioteket "music".'];
        yield 'root overlaps, th' => [LibraryRootOverlapsException::with('/data/music/jazz', 'music', '/data/music'), 'th', 'พาธ "/data/music/jazz" อยู่ภายในหรือครอบคลุมรูท "/data/music" ของไลบรารี "music"'];
        yield 'all files missing, da' => [LibraryMediaFilesAllMissingException::underRoot('/data/music'), 'da', 'Ingen af filerne findes i biblioteksmappen /data/music; dens lager er måske ikke monteret. Intet blev slettet.'];
        yield 'all files missing, th' => [LibraryMediaFilesAllMissingException::underRoot('/data/music'), 'th', 'ไม่พบไฟล์ใดเลยในโฟลเดอร์ไลบรารี /data/music อาจยังไม่ได้เมานต์ที่เก็บข้อมูล ไม่มีการลบใดๆ'];
        yield 'invalid type, th' => [InvalidLibraryTypeException::forType('vinyl'), 'th', 'ประเภทไลบรารี "vinyl" ไม่ถูกต้อง ต้องเป็นค่าหนึ่งใน: music, podcast, audiobook, movie, tv_show'];
    }

    #[DataProvider('translations')]
    public function testTheMessageIsTranslatedIntoTheLocale(\Throwable&TranslatableInterface $exception, string $locale, string $expected): void
    {
        self::assertSame($expected, $exception->trans(self::translator(), $locale));
    }

    private static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'da', 'th'] as $locale) {
            $translator->addResource('yaml', sprintf('%s/translations/library+intl-icu.%s.yaml', dirname(__DIR__, 5), $locale), $locale, 'library+intl-icu');
        }

        return $translator;
    }
}
