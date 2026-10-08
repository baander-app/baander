<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The type is not a LibraryType value; HTTP answers 422 in the request locale and console commands exit INVALID. */
final class InvalidLibraryTypeException extends InvalidInputException implements TranslatableInterface
{
    private function __construct(private readonly string $type, private readonly string $allowed)
    {
        parent::__construct(sprintf('Invalid library type "%s". Allowed: %s.', $type, $allowed));
    }

    public static function forType(string $type): self
    {
        return new self($type, implode(', ', array_column(LibraryType::cases(), 'value')));
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('errors.invalid_type_with_allowed', ['type' => $this->type, 'allowed' => $this->allowed], 'library', $locale);
    }
}
