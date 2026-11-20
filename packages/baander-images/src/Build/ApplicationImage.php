<?php

declare(strict_types=1);

namespace Baander\Images\Build;

use Baander\Containers\Docker\Client;
use Baander\Configuration\Images\ImageCatalog;

final readonly class ApplicationImage
{
    public function __construct(private Client $docker, private ImageCatalog $images) {}

    public function build(string $source, string $target, string $revision, string $tag, ?string $logPath = null, BuildOptions $options = new BuildOptions()): string
    {
        $request = BuildRequest::fromSource($source, $target, $revision, $tag, $this->images->role('operatorHelper'), $this->images->role('ffmpeg'), $options);
        $docker = $options->builder === null ? $this->docker : $this->docker->withBuilder($options->builder);
        $docker->run($request->arguments, timeout: null, logPath: $logPath);
        return $request->imageId($docker->run(['image', 'inspect', $tag])->stdout);
    }
}
