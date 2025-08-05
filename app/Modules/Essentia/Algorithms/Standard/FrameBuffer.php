<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * FrameBuffer


Inputs:

  [vector_real] frame - the input audio frame


Outputs:

  [vector_real] frame - the buffered audio frame


Parameters:

  bufferSize:
    integer ∈ (0,inf) (default = 2048)
    the buffer size

  zeroPadding:
    bool ∈ {true,false} (default = true)
    initialize the buffer with zeros (output zero-padded buffer frames if
    `true`, otherwise output empty frames until a full buffer is accumulated)


Description:

  This algorithm buffers input non-overlapping audio frames into longer
  overlapping frames with a hop sizes equal to input frame size.
  
  In standard mode, each compute() call updates and outputs the gathered
  buffer.
  
  Input frames can be of variate length. Input frames longer than the buffer
  size will be cropped. Empty input frames will raise an exception.
 * 
 * Category: Standard
 * Mode: standard
 */
class FrameBuffer extends BaseAlgorithm
{
    protected string $algorithmName = 'FrameBuffer';
    protected string $mode = 'standard';
    protected string $category = 'Standard';

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
                "Failed to compute FrameBuffer: " . $e->getMessage(),
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