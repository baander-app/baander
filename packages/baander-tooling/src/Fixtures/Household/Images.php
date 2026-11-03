<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use Baander\Tooling\Build\BuildOptions;
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Resources\ImageLedger;

final readonly class Images
{
    public const string OVERLAY_LABEL = 'org.baander.household.overlay';

    public function __construct(private Client $docker, private string $artifacts, private ApplicationLayout $layout) {}

    /** @return array<string,mixed> */
    public function prepare(string $appBaseId, string $operatorBaseId, string $sourceRevision, string $datasetManifest): array
    {
        foreach ([$appBaseId, $operatorBaseId] as $id) {
            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $id) !== 1) {
                throw new \InvalidArgumentException('Household overlays require immutable base image IDs.');
            }
        }
        if (preg_match('/^[a-f0-9]{40,64}$/D', $sourceRevision) !== 1) {
            throw new \InvalidArgumentException('Household overlays require the original source revision.');
        }
        PrivateDirectory::assertNoSymlinks($datasetManifest);
        $dataset = @file_get_contents($datasetManifest, length: 16777217);
        if ($dataset === false || strlen($dataset) > 16777216 || !is_array(json_decode($dataset, true, flags: JSON_THROW_ON_ERROR))) {
            throw new \InvalidArgumentException('Household overlay requires a bounded dataset manifest.');
        }
        PrivateDirectory::prepare($this->artifacts);
        $context = rtrim($this->artifacts, '/') . '/context-' . bin2hex(random_bytes(16));
        PrivateDirectory::prepare($context);
        $files = ['files/var/household/dataset.json' => $dataset, 'files/config/services_prod.yaml' => $this->services(), 'autoload.php' => $this->autoload()];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $path) {
            PrivateDirectory::assertNoSymlinks($path);
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Cannot read household fixture tooling.');
            }
            $files['files/packages/baander-tooling/src/Fixtures/Household/' . basename($path)] = $contents;
        }
        $wrapperHashes = [];
        foreach (['ffmpeg', 'ffprobe'] as $executable) {
            $wrapper = NativeAudit::wrapper($executable, $this->layout);
            $files['wrappers/' . $executable] = $wrapper;
            $wrapperHashes[$executable] = hash('sha256', $wrapper);
        }
        ksort($files);
        $hashes = [];
        foreach ($files as $path => $contents) {
            AtomicFile::write($context . '/' . $path, $contents, str_starts_with($path, 'wrappers/') ? 0755 : 0600);
            $hashes[$path] = hash('sha256', $contents);
        }
        $this->imageFilePermissions($context . '/files');
        $template = $this->dockerfile();
        $manifest = ['version' => 1, 'appBase' => $appBaseId, 'operatorBase' => $operatorBaseId,
            'sourceRevision' => $sourceRevision, 'layout' => $this->layout->toArray(), 'files' => $hashes,
            'dockerfileTemplateSha256' => hash('sha256', $template)];
        $digest = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $dockerfile = str_replace('__OVERLAY_DIGEST__', $digest, $template);
        AtomicFile::write($context . '/Dockerfile', $dockerfile);
        AtomicFile::writeJson($context . '/overlay-manifest.json', $manifest);
        return ['context' => $context, 'manifest' => $manifest, 'manifestDigest' => $digest,
            'dockerfileSha256' => hash('sha256', $dockerfile), 'wrapperHashes' => $wrapperHashes,
            'metadataManifestPath' => $this->layout->path('var/household/dataset.json'),
            'metadataAuditPath' => $this->layout->path('storage/household-audit/metadata.jsonl'),
            'nativeAuditPath' => $this->layout->path('storage/household-audit/native.jsonl')];
    }

    /** @return array<string,mixed> */
    public function build(string $appBaseId, string $operatorBaseId, string $sourceRevision, string $datasetManifest, BuildOptions $options = new BuildOptions()): array
    {
        if ($options->additionalTags !== []) {
            throw new \InvalidArgumentException('Fixture image tags are owned by the qualification run.');
        }
        PrivateDirectory::prepare($this->artifacts);
        $receipt = rtrim($this->artifacts, '/') . '/images.json';
        if (file_exists($receipt)) {
            throw new \RuntimeException('Preserve or clean up the existing fixture image receipt before rebuilding.');
        }
        $prepared = $this->prepare($appBaseId, $operatorBaseId, $sourceRevision, $datasetManifest);
        $ownershipReceipt = rtrim($this->artifacts, '/') . '/image-ownership.json';
        $result = ['version' => 1, 'phase' => 'preparing', ...$prepared, 'receipt' => $receipt, 'ownershipReceipt' => $ownershipReceipt,
            'target' => $this->docker->target->toArray()];
        AtomicFile::writeJson($receipt, $result);
        $this->docker->validateIdentity();
        foreach ([$appBaseId, $operatorBaseId] as $id) {
            $image = $this->inspect($id);
            if ($image['Id'] !== $id || ApplicationLayout::fromImage($image)->toArray() !== $this->layout->toArray()
                || ($image['Config']['Labels']['org.opencontainers.image.revision'] ?? null) !== $sourceRevision
                || isset($image['Config']['Labels'][self::OVERLAY_LABEL])) {
                throw new \RuntimeException('Qualification base image identity, layout or source revision does not match.');
            }
        }
        $ledger = new ImageLedger($ownershipReceipt, $this->docker);
        $appAlias = $ledger->alias('household-app-base', $appBaseId);
        $operatorAlias = $ledger->alias('household-operator-base', $operatorBaseId);
        $result['inputAliases'] = ['app' => $appAlias, 'operator' => $operatorAlias];
        AtomicFile::writeJson($receipt, $result);
        foreach (['app', 'worker', 'operator'] as $target) {
            $tag = 'baander-household-' . $ledger->token . ':' . $target;
            $ledger->intend($tag);
            $ledger->validateAliases($this->docker);
            $arguments = $options->buildx ? ['buildx', 'build', '--load'] : ['build'];
            foreach (['--cache-from' => $options->cacheFrom, '--cache-to' => $options->cacheTo] as $flag => $values) {
                foreach ($values as $value) {
                    array_push($arguments, $flag, $value);
                }
            }
            array_push(
                $arguments,
                '--pull=false',
                '--network=none',
                '--target',
                $target,
                '--tag',
                $tag,
                '--build-arg',
                'APP_BASE=' . $appAlias,
                '--build-arg',
                'OPERATOR_BASE=' . $operatorAlias,
                '--label',
                'org.baander.tooling.run=' . $ledger->token,
                '--label',
                self::OVERLAY_LABEL . '=' . $prepared['manifestDigest'],
                $prepared['context'],
            );
            $this->docker->run($arguments, timeout: null, logPath: rtrim($this->artifacts, '/') . '/build-' . $target . '.log');
            $ledger->observe($tag);
            $image = $this->inspect($tag);
            if (ApplicationLayout::fromImage($image)->toArray() !== $this->layout->toArray()
                || ($image['Config']['Labels']['org.opencontainers.image.revision'] ?? null) !== $sourceRevision
                || ($image['Config']['Labels'][self::OVERLAY_LABEL] ?? null) !== $prepared['manifestDigest']) {
                throw new \RuntimeException('Built fixture image lost its source, layout or overlay identity.');
            }
            $result[$target] = $image['Id'];
            $result['tags'][$target] = $tag;
            AtomicFile::writeJson($receipt, $result);
        }
        $result['phase'] = 'ready';
        AtomicFile::writeJson($receipt, $result);
        Collector::remove($prepared['context']);
        return $result;
    }

    /** @return array<string,mixed> */
    private function inspect(string $reference): array
    {
        $images = json_decode($this->docker->run(['image', 'inspect', $reference])->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($images) || !array_is_list($images) || count($images) !== 1 || !is_array($images[0])
            || !is_string($images[0]['Id'] ?? null) || preg_match('/^sha256:[a-f0-9]{64}$/D', $images[0]['Id']) !== 1) {
            throw new \RuntimeException('Expected one immutable fixture image.');
        }
        return $images[0];
    }

    private function imageFilePermissions(string $directory): void
    {
        if (!chmod($directory, 0755)) {
            throw new \RuntimeException('Cannot set copied image directory permissions.');
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if (!chmod($entry->getPathname(), $entry->isDir() ? 0755 : 0644)) {
                throw new \RuntimeException('Cannot set copied image file permissions.');
            }
        }
    }

    private function services(): string
    {
        return <<<'YAML'
services:
    baander.provider_http_transport:
        class: Symfony\Component\HttpClient\MockHttpClient
        autowire: false
        autoconfigure: false
        factory: ['Baander\Tooling\Fixtures\Household\MetadataTransport', 'create']
        arguments:
            - '%kernel.project_dir%/var/household/dataset.json'
            - '%kernel.project_dir%/storage/household-audit/metadata.jsonl'
    App\Shared\Infrastructure\Http\PublicDestinationPolicy:
        arguments:
            $dnsResolver: !service
                class: Closure
                factory: ['Baander\Tooling\Fixtures\Household\MetadataTransport', 'dnsResolver']
YAML;
    }

    private function autoload(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$path = $argv[1];
$document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($document) || !is_array($document['autoload'] ?? null)) {
    throw new RuntimeException('Fixture overlay requires the application Composer autoload configuration.');
}
$classmap = $document['autoload']['classmap'] ?? [];
if (!is_array($classmap) || !array_is_list($classmap)) {
    throw new RuntimeException('Fixture overlay requires a Composer classmap list.');
}
$class = 'packages/baander-tooling/src/Fixtures/Household/MetadataTransport.php';
if (!in_array($class, $classmap, true)) {
    $classmap[] = $class;
}
$document['autoload']['classmap'] = $classmap;
$output = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($path, $output) !== strlen($output)) {
    throw new RuntimeException('Cannot record fixture Composer classmap.');
}
PHP;
    }

    private function dockerfile(): string
    {
        $composer = $this->layout->path('composer.json');
        $copy = json_encode(['files/', $this->layout->root . '/'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return <<<DOCKERFILE
ARG APP_BASE
ARG OPERATOR_BASE
FROM \${OPERATOR_BASE} AS household-operator-helper
FROM \${APP_BASE} AS app
USER root
RUN test ! -e /usr/bin/ffmpeg.household-real && test ! -e /usr/bin/ffprobe.household-real && mv /usr/bin/ffmpeg /usr/bin/ffmpeg.household-real && mv /usr/bin/ffprobe /usr/bin/ffprobe.household-real
COPY --chown=www-data:www-data {$copy}
COPY ["autoload.php", "/tmp/household-autoload.php"]
RUN php /tmp/household-autoload.php "{$composer}" && rm /tmp/household-autoload.php
COPY --chmod=0755 ["wrappers/ffmpeg", "/usr/bin/ffmpeg"]
COPY --chmod=0755 ["wrappers/ffprobe", "/usr/bin/ffprobe"]
LABEL org.baander.household.overlay="__OVERLAY_DIGEST__"
USER www-data
RUN COMPOSER_MEMORY_LIMIT=-1 composer dump-autoload --no-dev --optimize --no-interaction --no-scripts
FROM app AS worker
HEALTHCHECK NONE
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/start-worker.sh"]
CMD []
FROM app AS operator
USER root
COPY --from=household-operator-helper /usr/local/bin/docker /usr/local/bin/docker
HEALTHCHECK NONE
ENTRYPOINT ["/usr/local/bin/start-operator.sh"]
CMD []

DOCKERFILE;
    }
}
