<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer;

interface TerminalLogGuardInterface
{
    public function begin(): void;

    public function end(): void;
}
