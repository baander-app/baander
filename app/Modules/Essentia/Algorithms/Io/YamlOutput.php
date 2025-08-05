<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * YamlOutput


Inputs:

  [pool] pool - Pool to serialize into a YAML formatted file


Parameters:

  doubleCheck:
    bool (default = false)
    whether to double-check if the file has been correctly written to the disk

  filename:
    string (default = "-")
    output filename (use '-' to emit to stdout)

  format:
    string ∈ {json,yaml} (default = "yaml")
    whether to output data in JSON or YAML format

  indent:
    integer (default = 4)
    (json only) how many characters to indent each line, or 0 for no newlines

  writeVersion:
    bool (default = true)
    whether to write the essentia version to the output file


Description:

  This algorithm emits a YAML or JSON representation of a Pool.
  
  Each descriptor key in the Pool is decomposed into different nodes of the
  YAML (JSON) format by splitting on the '.' character. For example a Pool that
  looks like this:
  
      foo.bar.some.thing: [23.1, 65.2, 21.3]
  
  will be emitted as:
  
      metadata:
          essentia:
              version: <version-number>
  
      foo:
          bar:
              some:
                  thing: [23.1, 65.2, 21.3]
 * 
 * Category: Io
 * Mode: standard
 */
class YamlOutput extends BaseAlgorithm
{
    protected string $algorithmName = 'YamlOutput';
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
                "Failed to compute YamlOutput: " . $e->getMessage(),
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