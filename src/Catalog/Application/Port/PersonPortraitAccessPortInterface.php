<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface PersonPortraitAccessPortInterface
{
    public function canReadPortrait(PublicId $imagePublicId, LibraryReadScope $scope): bool;
}
