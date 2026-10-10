<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Shared\Application\Exception\ConflictException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Another scan or delete with files holds a live claim on the library, which this one needed;
 * the details name the holder. HTTP answers 409 in the request locale and console commands fail.
 */
final class LibraryBusyException extends ConflictException implements TranslatableInterface
{
    private function __construct(private readonly string $name, private readonly LibraryClaimKind $holder)
    {
        parent::__construct(
            sprintf(match ($holder) {
                LibraryClaimKind::Scan => 'A scan is already in progress for the library "%s".',
                LibraryClaimKind::Delete => 'A delete with files is in progress for the library "%s".',
            }, $name),
            ['reason' => 'library_busy', 'holder' => $holder->value],
        );
    }

    public static function heldBy(string $name, LibraryClaimKind $holder): self
    {
        return new self($name, $holder);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.library_busy.' . $this->holder->value, ['library' => $this->name], 'library', $locale);
    }
}
