<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class DeliverySelection
{
    public function __construct(public string $container, public ?int $videoStreamIndex, public ?int $audioStreamIndex, public string $transport = 'file')
    {
    }
}
