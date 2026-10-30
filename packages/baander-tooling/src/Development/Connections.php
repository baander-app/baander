<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Process\CommandExecutor;

/** Hydrates captured transport; it never resolves ambient Docker settings. */
final readonly class Connections
{
    /** @param array<string,string> $environment */
    public function __construct(private CommandExecutor $executor, private array $environment) {}

    /** @return array<string,string> */
    public function environment(): array
    {
        return $this->environment;
    }

    /** @param array<string,mixed> $target */
    public function client(array $target): Client
    {
        return new Client($this->executor, Target::fromArray($target), $this->environment);
    }
    /** Snapshot paths and the original context name are provenance, not daemon ownership.
     * @param array<string,mixed> $first
     * @param array<string,mixed> $second
     */
    public static function sameTarget(array $first, array $second): bool
    {
        $a = Target::fromArray($first);
        $b = Target::fromArray($second);
        $aFiles = [];
        $bFiles = [];
        foreach ($a->files as $path => $digest) {
            $aFiles[basename($path)] = $digest;
        }
        foreach ($b->files as $path => $digest) {
            $bFiles[basename($path)] = $digest;
        }
        ksort($aFiles);
        ksort($bFiles);
        return $a->executable === $b->executable && $a->endpoint === $b->endpoint && $a->daemonId === $b->daemonId
            && $aFiles === $bFiles && ($a->transportArguments[0] ?? null) === ($b->transportArguments[0] ?? null);
    }

}
