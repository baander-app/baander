<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\LibraryProviderOrder;
use App\Shared\Interface\Resource\AbstractResource;
use OpenApi\Spec as OA;

#[OA\Schema(
    type: 'object',
    properties: ['providers' => new OA\Schema(type: 'array', items: new OA\Schema(type: 'string'))],
    required: ['providers'],
    component: 'LibraryProviderOrderResource',
)]
final class LibraryProviderOrderResource extends AbstractResource
{
    /** @return array{providers: list<string>} */
    public static function from(mixed $source): array
    {
        assert($source instanceof LibraryProviderOrder);

        return ['providers' => $source->getProviderIds()];
    }
}
