<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * YamlInput


Outputs:

  [pool] pool - Pool of deserialized values


Parameters:

  filename:
    string
    Input filename

  format:
    string ∈ {json,yaml} (default = "yaml")
    whether to the input file is in JSON or YAML format


Description:

  This algorithm deserializes a file formatted in YAML to a Pool. This file can
  be serialized back into a YAML file using the YamlOutput algorithm. See the
  documentation for YamlOutput for more information on the specification of the
  YAML file.
  
  Note: If an empty sequence is encountered (i.e. "[]"), this algorithm will
  assume it was intended to be a sequence of Reals and will add it to the
  output pool accordingly. This only applies to sequences which contain empty
  sequences. Empty sequences (which are not subsequences) are not possible in a
  Pool and therefore will be ignored if encountered (i.e. foo: [] (ignored),
  but foo: [[]] (added as a vector of one empty vector of reals).
 * 
 * Category: Io
 * Mode: standard
 */
class YamlInput extends BaseAlgorithm
{
    protected string $algorithmName = 'YamlInput';
    protected string $mode = 'standard';
    protected string $category = 'Io';

    public function compute($input): array
    {
        try {
            // Input validation based on algorithm type
            $this->validateAlgorithmInput($input);
            
            // Convert input to appropriate format
            $processedInput = $this->prepareInput($input);
            
            // Execute the algorithm
            $result = $this->executeAlgorithm($processedInput);
            
            return $this->processOutput($result);
            
        } catch (\Exception $e) {
            throw new AlgorithmException(
                "Failed to compute YamlInput: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function validateAlgorithmInput($input): void
    {
        // Category-specific input validation
        switch ($this->category) {
            case 'Spectral':
            case 'Temporal':
                $this->validateInput($input, 'array');
                break;
            case 'Io':
                if (!is_string($input) && !($input instanceof AudioVector)) {
                    throw new AlgorithmException('IO algorithms expect string path or AudioVector');
                }
                break;
            default:
                // Generic validation
                if (!is_array($input) && !($input instanceof AudioVector) && !is_numeric($input)) {
                    throw new AlgorithmException('Unsupported input type for algorithm');
                }
        }
    }

    private function prepareInput($input)
    {
        if ($input instanceof AudioVector) {
            return $input->toCArray($this->essentia->getFFI());
        }
        
        return $input;
    }

    private function executeAlgorithm($input)
    {
        // This would contain the actual FFI calls to Essentia
        // Implementation depends on the specific algorithm
        
        // Placeholder for algorithm execution
        return [];
    }

    private function processOutput($result): array
    {
        // Process and format the output from Essentia
        return is_array($result) ? $result : [$result];
    }
}