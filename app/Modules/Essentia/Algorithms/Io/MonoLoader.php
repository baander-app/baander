<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * MonoLoader


Outputs:

  [vector_real] audio - the audio signal


Parameters:

  audioStream:
    integer ∈ [0,inf) (default = 0)
    audio stream index to be loaded. Other streams are no taken into account
    (e.g. if stream 0 is video and 1 is audio use index 0 to access it.)

  downmix:
    string ∈ {left,right,mix} (default = "mix")
    the mixing type for stereo files

  filename:
    string
    the name of the file from which to read

  resampleQuality:
    integer ∈ [0,4] (default = 1)
    the resampling quality, 0 for best quality, 4 for fast linear approximation

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the desired output sampling rate [Hz]


Description:

  This algorithm loads the raw audio data from an audio file and downmixes it
  to mono. Audio is resampled using Resample in case the given sampling rate
  does not match the sampling rate of the input signal.
  
  This algorithm uses AudioLoader and thus inherits all of its input
  requirements and exceptions.
 * 
 * Category: Io
 * Mode: standard
 */
class MonoLoader extends BaseAlgorithm
{
    protected string $algorithmName = 'MonoLoader';
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
                "Failed to compute MonoLoader: " . $e->getMessage(),
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