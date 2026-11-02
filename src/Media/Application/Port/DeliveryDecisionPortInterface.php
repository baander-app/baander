<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Application\DTO\DeliveryDecision;
use App\Media\Application\DTO\DeliveryDestination;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface DeliveryDecisionPortInterface
{
    public function video(Uuid $owner, PublicId $id, DeliveryDestination $destination): DeliveryDecision;
    public function track(Uuid $owner, PublicId $id, DeliveryDestination $destination): DeliveryDecision;
}
