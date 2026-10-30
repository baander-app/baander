<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class ImageSelection
{
    /** @param list<string> $capabilities */
    public function __construct(public string $reference, public array $capabilities, public ?string $playwrightVersion = null)
    {
        $component = '[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*';
        $host = '[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?';
        $registry = '(?:' . $host . '(?:\.' . $host . ')*(?::[0-9]+)?|\[[a-fA-F0-9:]+\](?::[0-9]+)?)';
        $pattern = '~^(?:' . $registry . '/)?' . $component . '(?:/' . $component . ')*(?::[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127})?(?:@sha256:[a-fA-F0-9]{64})?$~D';
        if (preg_match($pattern, $reference) !== 1) {
            throw new \InvalidArgumentException('Invalid image reference.');
        }
        foreach ($capabilities as $capability) {
            if (preg_match('/^[a-z][a-z0-9-]*$/D', $capability) !== 1) {
                throw new \InvalidArgumentException('Invalid image capability.');
            }
        }
        if ($playwrightVersion !== null && preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D', $playwrightVersion) !== 1) {
            throw new \InvalidArgumentException('Invalid Playwright package version.');
        }
    }

    public function assertPlaywrightVersion(string $installedVersion): void
    {
        if ($this->playwrightVersion === null || $installedVersion !== $this->playwrightVersion) {
            throw new \InvalidArgumentException('Browser image requires Playwright ' . ($this->playwrightVersion ?? '(undeclared)') . '; installed package is ' . $installedVersion . '.');
        }
    }

    /** @return array{reference:string,capabilities:list<string>,playwrightVersion:?string} */
    public function toArray(): array
    {
        return ['reference' => $this->reference, 'capabilities' => $this->capabilities, 'playwrightVersion' => $this->playwrightVersion];
    }
}
