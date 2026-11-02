<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class DeliverySelection
{
    public function __construct(public string $container, public ?int $videoStreamIndex, public ?int $audioStreamIndex, public string $transport = 'file')
    {
    }
}
