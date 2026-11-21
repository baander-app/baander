<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface VideoPlaybackInformationPort
{
    /** @return array<string, mixed>|null */
    public function information(PublicId $id, LibraryReadScope $scope): ?array;
    public function image(PublicId $id, LibraryReadScope $scope): ?string;
}
