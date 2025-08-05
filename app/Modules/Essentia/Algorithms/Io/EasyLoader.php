<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Io;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * EasyLoader


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

  endTime:
    real ∈ [0,inf) (default = 1000000)
    the end time of the slice to be extracted [s]

  filename:
    string
    the name of the file from which to read

  replayGain:
    real ∈ (-inf,inf) (default = -6)
    the value of the replayGain that should be used to normalize the signal
    [dB]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the output sampling rate [Hz]

  startTime:
    real ∈ [0,inf) (default = 0)
    the start time of the slice to be extracted [s]


Description:

  This algorithm loads the raw audio data from an audio file, downmixes it to
  mono and normalizes using replayGain. The audio is resampled in case the
  given sampling rate does not match the sampling rate of the input signal and
  is normalized by the given replayGain value.
  
  This algorithm uses MonoLoader and therefore inherits all of its input
  requirements and exceptions.
  
  References:
    [1] Replay Gain - A Proposed Standard,
    http://replaygain.hydrogenaudio.org
 * 
 * Category: Io
 * Mode: standard
 */
class EasyLoader extends BaseAlgorithm
{
    protected string $algorithmName = 'EasyLoader';
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
                "Failed to compute EasyLoader: " . $e->getMessage(),
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