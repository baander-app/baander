<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

final class OperatorRun
{
    public string $appHome = '';
    /** @var list<string> */
    public array $mediaMounts = [];
    public string $controllerId = '';
    public string $dependencyCpu = '';
    public string $webPort = '';
    public string $artifacts = '';
    public string $baseId = '';
    public string $boot = '';
    public string $controlName = '';
    public string $controllerCli = '';
    public string $controllerImage = '';
    public string $daemonId = '';
    public string $developmentSourcePath = '';
    public string $faultContainer = '';
    public string $fixtureBase = '';
    public string $fixtureImage = '';
    public string $imageId = '';
    public string $ipcVolume = '';
    public string $lifecyclePhase = '';
    public bool $mediaObservation = false;
    public string $namespace = '';
    public string $runId = '';
    public string $secretsVolume = '';
    public string $sourceVolume = '';
    public string $stateVolume = '';
    public string $storageVolume = '';
    public bool $twoCommand = false;
    public string $webBase = '';
    public string $webName = '';
    public string $work = '';
    public string $workerName = '';
}
