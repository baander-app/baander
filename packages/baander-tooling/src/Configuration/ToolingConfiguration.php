<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final readonly class ToolingConfiguration
{
    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        JsonObject::keys($data, ['version', 'images', 'docker', 'budgets', 'checkpoint', 'provenance'], 'resolved tooling configuration');
        if (($data['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported resolved tooling configuration version.');
        }
        $images = [];
        foreach (self::map($data, 'images') as $role => $definition) {
            if (!is_array($definition) || !is_array($definition['capabilities'] ?? null) || !array_is_list($definition['capabilities'])) {
                throw new \InvalidArgumentException('Invalid saved image selection.');
            }
            JsonObject::keys($definition, ['reference', 'capabilities', 'playwrightVersion'], 'saved image selection');
            foreach ($definition['capabilities'] as $capability) {
                if (!is_string($capability)) {
                    throw new \InvalidArgumentException('Invalid saved image capability.');
                }
            }
            $version = self::optionalString($definition, 'playwrightVersion');
            if ($role === 'browser' && $version === null) {
                throw new \InvalidArgumentException('Browser role requires its compatible Playwright package version.');
            }
            $images[$role] = new ImageSelection(self::string($definition, 'reference'), $definition['capabilities'], $version);
        }
        $docker = self::map($data, 'docker');
        JsonObject::keys($docker, ['executable', 'host', 'context'], 'saved Docker settings');
        $budgets = [];
        foreach (self::map($data, 'budgets') as $name => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException('Invalid saved workflow budget.');
            }
            $budgets[$name] = (float) $value;
        }
        $provenance = self::map($data, 'provenance');
        foreach ($provenance as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid saved configuration provenance.');
            }
        }
        $checkpoint = $data['checkpoint'] ?? null;
        if ($checkpoint !== null) {
            if (!is_array($checkpoint)) {
                throw new \InvalidArgumentException('Invalid saved checkpoint selection.');
            }
            JsonObject::keys($checkpoint, ['name', 'path'], 'saved checkpoint');
            $checkpoint = new CheckpointSelection(self::string($checkpoint, 'name'), self::string($checkpoint, 'path'));
        }
        return new self(new ImageCatalog($images), new DockerSettings(self::string($docker, 'executable'), self::optionalString($docker, 'host'), self::optionalString($docker, 'context')), new WorkflowBudgets($budgets), $checkpoint, $provenance);
    }

    /** @param array<string,string> $environment
     * @param array<string,mixed> $flags
     */
    public static function forRepository(string $root, array $environment, array $flags = []): self
    {
        $defaults = JsonObject::read(dirname(__DIR__, 2) . '/resources/tooling-defaults.json');
        $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded($defaults['budgets']['process'], $environment, $root));
        try {
            $local = RepositoryConfiguration::local($executor, $root, $environment);
            return self::resolve($flags, $environment, $local['tooling'] ?? []);
        } finally {
            $executor->close();
        }
    }

    /** @param array<string,string> $provenance */
    private function __construct(
        public ImageCatalog $images,
        public DockerSettings $docker,
        public WorkflowBudgets $budgets,
        public ?CheckpointSelection $checkpoint,
        public array $provenance,
    ) {}

    /**
     * @param array<string,mixed> $flags Explicit typed settings, with the same keys as the local tooling object.
     * @param array<string,string> $environment Captured invocation environment; unrelated keys are ignored.
     * @param array<string,mixed> $local The selected local file's tooling object, without libraries.
     */
    public static function resolve(array $flags = [], array $environment = [], array $local = []): self
    {
        $defaults = JsonObject::read(dirname(__DIR__, 2) . '/resources/tooling-defaults.json');
        JsonObject::keys($defaults, ['version', 'images', 'docker', 'budgets'], 'tooling defaults');
        if (($defaults['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported tooling defaults version.');
        }
        $values = self::map($defaults, 'images');
        $docker = self::map($defaults, 'docker');
        $budgets = self::map($defaults, 'budgets');
        JsonObject::keys($docker, ['executable', 'host', 'context'], 'Docker defaults');
        $effective = ['images' => $values, 'docker' => $docker, 'budgets' => $budgets, 'checkpoints' => []];
        $provenance = [];
        foreach (['images', 'docker', 'budgets'] as $section) {
            foreach ($effective[$section] as $key => $value) {
                if ($section === 'images') {
                    if (!is_array($value)) {
                        throw new \InvalidArgumentException('Image roles must be objects.');
                    }
                    foreach ($value as $leaf => $_) {
                        $provenance['images.' . $key . '.' . $leaf] = 'defaults';
                    }
                } else {
                    $provenance[$section . '.' . $key] = 'defaults';
                }
            }
        }
        foreach (['local' => $local, 'environment' => self::environment($environment), 'flags' => $flags] as $source => $overrides) {
            self::apply($effective, $provenance, $overrides, $source);
        }
        $images = [];
        foreach (self::map($effective, 'images') as $role => $definition) {
            if (!is_array($definition)) {
                throw new \InvalidArgumentException('Image roles must be objects.');
            }
            JsonObject::keys($definition, ['reference', 'capabilities', 'playwrightVersion'], 'image role ' . $role);
            $reference = self::string($definition, 'reference');
            $capabilities = $definition['capabilities'] ?? null;
            if (!is_array($capabilities) || !array_is_list($capabilities)) {
                throw new \InvalidArgumentException('Image capabilities must be a list.');
            }
            foreach ($capabilities as $capability) {
                if (!is_string($capability)) {
                    throw new \InvalidArgumentException('Image capabilities must be strings.');
                }
            }
            $version = isset($definition['playwrightVersion']) ? self::string($definition, 'playwrightVersion') : null;
            if ($role === 'browser' && $version === null) {
                throw new \InvalidArgumentException('Browser role requires its compatible Playwright package version.');
            }
            $images[$role] = new ImageSelection($reference, $capabilities, $version);
        }
        $docker = self::map($effective, 'docker');
        $budgetValues = [];
        foreach (self::map($effective, 'budgets') as $name => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException('Workflow budgets must be numeric seconds: ' . $name);
            }
            $budgetValues[$name] = (float) $value;
        }
        $checkpoints = self::map($effective, 'checkpoints');
        if (count($checkpoints) > 1) {
            throw new \InvalidArgumentException('Only one exclusive checkpoint may be selected.');
        }
        $checkpoint = null;
        foreach ($checkpoints as $name => $path) {
            $checkpoint = new CheckpointSelection($name, self::string($checkpoints, $name));
        }
        return new self(
            new ImageCatalog($images),
            new DockerSettings(self::string($docker, 'executable'), self::optionalString($docker, 'host'), self::optionalString($docker, 'context')),
            new WorkflowBudgets($budgetValues),
            $checkpoint,
            $provenance,
        );
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException($key . ' must be an object.');
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException($key . ' must be a nonempty string without control characters.');
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::string($data, $key);
    }

    /** @param array<string,mixed> $effective
     * @param array<string,string> $provenance
     * @param array<string,mixed> $overrides
     */
    private static function apply(array &$effective, array &$provenance, array $overrides, string $source): void
    {
        JsonObject::keys($overrides, ['images', 'docker', 'budgets', 'checkpoints'], $source . ' tooling settings');
        foreach ($overrides as $section => $_) {
            $map = self::map($overrides, $section);
            $known = $section === 'checkpoints' ? ['controlCapacity', 'autoscaling'] : array_keys(self::map($effective, $section));
            JsonObject::keys($map, $known, $source . ' ' . $section);
            if ($section === 'docker') {
                if (array_key_exists('host', $map) && array_key_exists('context', $map)) {
                    throw new \InvalidArgumentException('Docker host and context are mutually exclusive.');
                }
                foreach (['host' => 'context', 'context' => 'host'] as $selector => $other) {
                    if (array_key_exists($selector, $map)) {
                        $effective['docker'][$other] = null;
                        $provenance['docker.' . $other] = $source;
                    }
                }
            }
            foreach ($map as $key => $value) {
                if ($section === 'budgets' && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0)) {
                    throw new \InvalidArgumentException('Workflow budgets must be positive finite seconds: ' . $key);
                }
                if ($section === 'docker' || $section === 'checkpoints') {
                    self::string($map, $key);
                }
                if ($section === 'images') {
                    $role = self::map($map, $key);
                    JsonObject::keys($role, $key === 'browser' ? ['reference', 'playwrightVersion'] : ['reference'], $source . ' image ' . $key);
                    $current = self::map(self::map($effective, 'images'), $key);
                    if ($key === 'browser' && isset($role['reference']) && $role['reference'] !== $current['reference'] && !array_key_exists('playwrightVersion', $role)) {
                        throw new \InvalidArgumentException('A custom browser reference must declare its compatible Playwright package version.');
                    }
                    foreach ($role as $leaf => $setting) {
                        self::string($role, $leaf);
                        $current[$leaf] = $setting;
                        $provenance['images.' . $key . '.' . $leaf] = $source;
                    }
                    $effective['images'][$key] = $current;
                } else {
                    if ($value === null || $value === '') {
                        throw new \InvalidArgumentException('Explicit overrides must not be empty: ' . $section . '.' . $key);
                    }
                    $effective[$section][$key] = $value;
                    $provenance[$section . '.' . $key] = $source;
                }
            }
        }
    }

    /** @param array<string,string> $environment
     * @return array<string,mixed>
     */
    private static function environment(array $environment): array
    {
        $result = [];
        foreach (['BAANDER_DOCKER_EXECUTABLE' => 'executable', 'DOCKER_HOST' => 'host', 'DOCKER_CONTEXT' => 'context'] as $name => $key) {
            if (isset($environment[$name]) && $environment[$name] !== '') {
                $result['docker'][$key] = $environment[$name];
            }
        }
        if (isset($environment['BAANDER_TEST_DOCKER_CONTEXT']) && $environment['BAANDER_TEST_DOCKER_CONTEXT'] !== '') {
            $result['docker']['context'] = $environment['BAANDER_TEST_DOCKER_CONTEXT'];
        }
        // Docker's ambient context selector takes precedence over its host variable.
        if (isset($result['docker']['context'])) {
            unset($result['docker']['host']);
        }
        foreach (['BAANDER_TEST_IMAGE' => 'application', 'BAANDER_TEST_POSTGRES_IMAGE' => 'postgres', 'BAANDER_TEST_REDIS_IMAGE' => 'fixtureRedis', 'BAANDER_TEST_PROXY_IMAGE' => 'proxy', 'BAANDER_TEST_DOCKER_CLI_IMAGE' => 'operatorHelper', 'BAANDER_BROWSER_IMAGE' => 'browser', 'BAANDER_DEVELOPMENT_REDIS_IMAGE' => 'developmentRedis', 'BAANDER_NGINX_IMAGE' => 'nginx', 'BAANDER_TEST_MAILPIT_IMAGE' => 'mailpit'] as $name => $role) {
            if (isset($environment[$name]) && $environment[$name] !== '') {
                $result['images'][$role]['reference'] = $environment[$name];
            }
        }
        if (isset($environment['BAANDER_BROWSER_PLAYWRIGHT_VERSION']) && $environment['BAANDER_BROWSER_PLAYWRIGHT_VERSION'] !== '') {
            $result['images']['browser']['playwrightVersion'] = $environment['BAANDER_BROWSER_PLAYWRIGHT_VERSION'];
        }
        foreach (['BAANDER_TEST_TIMEOUT' => 'backend', 'BAANDER_CLEANUP_TIMEOUT' => 'cleanup'] as $name => $budget) {
            if (isset($environment[$name]) && $environment[$name] !== '') {
                if (!is_numeric($environment[$name])) {
                    throw new \InvalidArgumentException($name . ' must be positive finite seconds.');
                }
                $result['budgets'][$budget] = (float) $environment[$name];
            }
        }
        foreach (['BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => 'controlCapacity', 'BAANDER_TEST_AUTOSCALING_CHECKPOINT' => 'autoscaling'] as $name => $checkpoint) {
            if (isset($environment[$name]) && $environment[$name] !== '') {
                $result['checkpoints'][$checkpoint] = $environment[$name];
            }
        }
        return $result;
    }

    /** @return array{version:int,images:array<string,array{reference:string,capabilities:list<string>,playwrightVersion:?string}>,docker:array{executable:string,host:?string,context:?string},budgets:array<string,float>,checkpoint:?array{name:string,path:string},provenance:array<string,string>} */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'images' => $this->images->toArray(),
            'docker' => $this->docker->toArray(),
            'budgets' => $this->budgets->toArray(),
            'checkpoint' => $this->checkpoint === null ? null : ['name' => $this->checkpoint->name, 'path' => $this->checkpoint->path],
            'provenance' => $this->provenance,
        ];
    }
}
