<?php

declare(strict_types=1);

namespace App\Command\DocGenerator\Model;

final readonly class HandlerInfo
{
    /**
     * @param string $handlerFqcn Handler class FQCN
     * @param string $handlerShortName Handler short class name
     * @param string $commandFqcn Command/query class FQCN handled
     * @param string $commandShortName Command/query short class name
     * @param string $layer Layer the handler belongs to
     */
    public function __construct(
        public string $handlerFqcn,
        public string $handlerShortName,
        public string $commandFqcn,
        public string $commandShortName,
        public string $layer = 'Application',
    ) {}
}
