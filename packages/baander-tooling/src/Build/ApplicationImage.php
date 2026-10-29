<?php

declare(strict_types=1);

namespace Baander\Tooling\Build;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Docker\Client;

final readonly class ApplicationImage
{
    public function __construct(private Client $docker) {}

    public function build(string $source, string $target, string $revision, string $tag, ?string $logPath = null): string
    {
        $layout = ApplicationLayout::load($source . '/packages/baander-tooling/resources/application-layout.json');
        $this->docker->run([
            'build', '--target', $target,
            '--build-arg', 'APP_HOME=' . $layout->root,
            '--build-arg', 'BAANDER_SOURCE_REVISION=' . $revision,
            '--label', ApplicationLayout::IMAGE_LABEL . '=' . json_encode($layout->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            '--tag', $tag, $source,
        ], timeout: null, logPath: $logPath);
        $inspection = json_decode($this->docker->run(['image', 'inspect', $tag])->stdout, true, flags: JSON_THROW_ON_ERROR);
        $image = $inspection[0] ?? null;
        if (!is_array($image) || !is_string($image['Id'] ?? null) || preg_match('/^sha256:[a-f0-9]{64}$/D', $image['Id']) !== 1) {
            throw new \RuntimeException('Built application image has no immutable identity.');
        }
        if ($target !== 'dev-tools') {
            $observed = ApplicationLayout::fromImage($image);
            if ($observed->toArray() !== $layout->toArray()) {
                throw new \RuntimeException('Built image layout differs from its selected source contract.');
            }
        }
        return $image['Id'];
    }
}
