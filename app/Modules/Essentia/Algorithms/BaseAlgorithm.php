<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms;

use App\Modules\Essentia\EssentiaFFI;
use App\Modules\Essentia\Exceptions\{EssentiaException, ConfigurationException, AlgorithmException};
use App\Modules\Essentia\Types\AudioVector;

/**
 * Base class for all Essentia algorithm wrappers
 */
abstract class BaseAlgorithm
{
    protected EssentiaFFI $essentia;
    protected array $parameters = [];
    protected string $algorithmName = '';
    protected string $mode = 'standard';
    protected string $category = 'Standard';

    public function __construct(array $parameters = [])
    {
        $this->essentia = new EssentiaFFI();
        $this->parameters = $parameters;
        $this->configure($parameters);
    }

    abstract public function compute($input): array;

    protected function configure(array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            if (!$this->isValidParameter($key)) {
                throw new ConfigurationException("Invalid parameter: {$key}");
            }
            $this->parameters[$key] = $value;
        }
    }

    protected function isValidParameter(string $parameter): bool
    {
        // This would be overridden by specific algorithms
        return true;
    }

    protected function validateInput($input, string $expectedType): void
    {
        switch ($expectedType) {
            case 'array':
                if (!is_array($input)) {
                    throw new AlgorithmException('Expected array input');
                }
                break;
            case 'AudioVector':
                if (!($input instanceof AudioVector)) {
                    throw new AlgorithmException('Expected AudioVector input');
                }
                break;
            case 'numeric':
                if (!is_numeric($input)) {
                    throw new AlgorithmException('Expected numeric input');
                }
                break;
        }
    }

    public function getAlgorithmName(): string
    {
        return $this->algorithmName;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameter(string $key, $value): self
    {
        if (!$this->isValidParameter($key)) {
            throw new ConfigurationException("Invalid parameter: {$key}");
        }
        
        $this->parameters[$key] = $value;
        return $this;
    }
}