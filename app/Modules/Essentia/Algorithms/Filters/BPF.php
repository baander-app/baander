<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Filters;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * BPF


Inputs:

  [real] x - the input coordinate (x-axis)


Outputs:

  [real] y - the output coordinate (y-axis)


Parameters:

  xPoints:
    vector_real (default = [0, 1])
    the x-coordinates of the points forming the break-point function (the
    points must be arranged in ascending order and cannot contain duplicates)

  yPoints:
    vector_real (default = [0, 1])
    the y-coordinates of the points forming the break-point function


Description:

  This algorithm implements a break point function which linearly interpolates
  between discrete xy-coordinates to construct a continuous function.
  
  Exceptions are thrown when the size the vectors specified in parameters is
  not equal and at least they contain two elements. Also if the parameter
  vector for x-coordinates is not sorted ascendantly. A break point function
  cannot interpolate outside the range specified in parameter "xPoints". In
  that case an exception is thrown.
   
  References:
    [1] Linear interpolation - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Linear_interpolation
 * 
 * Category: Filters
 * Mode: standard
 */
class BPF extends BaseAlgorithm
{
    protected string $algorithmName = 'BPF';
    protected string $mode = 'standard';
    protected string $category = 'Filters';

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
                "Failed to compute BPF: " . $e->getMessage(),
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