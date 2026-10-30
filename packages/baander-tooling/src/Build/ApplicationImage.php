<?php

declare(strict_types=1);

namespace Baander\Tooling\Build;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Configuration\ImageCatalog;

final readonly class ApplicationImage
{
    public function __construct(private Client $docker, private ImageCatalog $images) {}

    public function build(string $source, string $target, string $revision, string $tag, ?string $logPath = null, BuildOptions $options = new BuildOptions()): string
    {
        $request = BuildRequest::fromSource($source, $target, $revision, $tag, $this->images->role('operatorHelper'), $options);
        $this->docker->run($request->arguments, timeout: null, logPath: $logPath);
        return $request->imageId($this->docker->run(['image', 'inspect', $tag])->stdout);
    }
}
