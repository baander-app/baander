<?php

declare(strict_types=1);

namespace App\Metadata\Application\Exception;

use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The path names no file inside a library, or a file whose format the tag readers do not support.
 *
 * HTTP answers 422 in the request locale and console commands exit with INVALID.
 */
final class UnreadableMediaFileException extends InvalidInputException implements TranslatableInterface
{
    private function __construct(string $message, private readonly string $translationKey)
    {
        parent::__construct($message);
    }

    /** Also the answer for a file outside every library, so it does not reveal which paths exist. */
    public static function missing(): self
    {
        return new self('Valid "path" to an existing file inside a library is required.', 'errors.valid_path_required');
    }

    public static function unsupportedFormat(): self
    {
        return new self('Unsupported audio format.', 'errors.unsupported_format');
    }

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->translationKey, [], 'metadata', $locale);
    }
}
