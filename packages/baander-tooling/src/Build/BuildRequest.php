<?php

declare(strict_types=1);

namespace Baander\Tooling\Build;

use Baander\Tooling\Configuration\{ApplicationLayout, ImageSelection};

final readonly class BuildRequest
{
    /** @param list<string> $arguments */
    private function __construct(public array $arguments, public ApplicationLayout $layout, private string $target) {}

    public static function fromSource(string $source, string $target, string $revision, string $tag, ImageSelection $operatorHelper, BuildOptions $options = new BuildOptions()): self
    {
        $layout = ApplicationLayout::load($source . '/packages/baander-tooling/resources/application-layout.json');
        $arguments = $options->buildx ? ['buildx', 'build', '--load'] : ['build'];
        foreach ([$tag, ...$options->additionalTags] as $name) {
            array_push($arguments, '--tag', $name);
        }
        foreach (['--cache-from' => $options->cacheFrom, '--cache-to' => $options->cacheTo] as $flag => $values) {
            foreach ($values as $value) {
                array_push($arguments, $flag, $value);
            }
        }
        $arguments = [
            ...$arguments, '--target', $target,
            '--build-arg', 'OPERATOR_HELPER_IMAGE=' . $operatorHelper->reference,
            '--build-arg', 'APP_HOME=' . $layout->root,
            '--build-arg', 'BAANDER_SOURCE_REVISION=' . $revision,
            '--label', ApplicationLayout::IMAGE_LABEL . '=' . json_encode($layout->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $source,
        ];
        return new self($arguments, $layout, $target);
    }

    public function imageId(string $inspectionJson): string
    {
        $inspection = json_decode($inspectionJson, true, flags: JSON_THROW_ON_ERROR);
        if (!str_starts_with(ltrim($inspectionJson), '[') || !is_array($inspection) || !array_is_list($inspection) || count($inspection) !== 1) {
            throw new \RuntimeException('Application image inspection must contain exactly one image.');
        }
        $image = $inspection[0];
        if (!is_array($image) || !is_string($image['Id'] ?? null) || preg_match('/^sha256:[a-f0-9]{64}$/D', $image['Id']) !== 1) {
            throw new \RuntimeException('Built application image has no immutable identity.');
        }
        if ($this->target !== 'dev-tools') {
            $observed = ApplicationLayout::fromImage($image);
            if ($observed->toArray() !== $this->layout->toArray()) {
                throw new \RuntimeException('Built image layout differs from its selected source contract.');
            }
        }
        return $image['Id'];
    }
}
