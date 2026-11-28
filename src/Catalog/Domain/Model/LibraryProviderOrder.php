<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final class LibraryProviderOrder
{
    private function __construct(private LibraryProviderOrderState $state) {}

    /**
     * @param array<array-key, string> $providerIds
     * @param list<string> $supported
     */
    public static function fromProviders(Uuid $libraryId, array $providerIds, array $supported): self
    {
        if (!array_is_list($providerIds)) {
            throw new InvalidArgumentException('Provider order must be a list.');
        }
        $ordered = $providerIds;
        sort($ordered, SORT_STRING);
        sort($supported, SORT_STRING);
        if ($ordered !== $supported || count(array_unique($ordered)) !== count($ordered)) {
            throw new InvalidArgumentException('Provider order must name every supported provider exactly once.');
        }

        return new self(new LibraryProviderOrderState($libraryId, $providerIds));
    }

    public static function reconstitute(LibraryProviderOrderState $state): self
    {
        return new self($state);
    }

    public function getLibraryId(): Uuid
    {
        return $this->state->libraryId;
    }

    /** @return list<string> */
    public function getProviderIds(): array
    {
        return $this->state->providerIds;
    }
}
