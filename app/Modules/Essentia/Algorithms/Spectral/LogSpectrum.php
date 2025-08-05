<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * LogSpectrum


Inputs:

  [vector_real] spectrum - spectrum frame


Outputs:

  [vector_real] logFreqSpectrum - log frequency spectrum frame
  [vector_real] meanTuning - normalized mean tuning frequency
         [real] localTuning - normalized local tuning frequency


Parameters:

  binsPerSemitone:
    real ∈ (0,inf) (default = 3)
     bins per semitone

  frameSize:
    integer ∈ (1,inf) (default = 1025)
    the input frame size of the spectrum vector

  nOctave:
    integer ∈ (0,10) (default = 7)
    the number of octave of the output vector

  rollOn:
    real ∈ [0,5] (default = 0)
    this removes low-frequency noise - useful in quiet recordings

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the input sample rate


Description:

  This algorithm computes spectrum with logarithmically distributed frequency
  bins. This code is ported from NNLS Chroma [1, 2].This algorithm also returns
  a local tuning that is retrieved for input frame and a global tuning that is
  updated with a moving average.
  
  Note: As the algorithm uses moving averages that are updated every frame it
  should be reset before  processing a new audio file. To do this call reset()
  (or configure())
  
  References:
    [1] Mauch, M., & Dixon, S. (2010, August). Approximate Note Transcription
    for the Improved Identification of Difficult Chords. In ISMIR (pp.
  135-140).
    [2] Chordino and NNLS Chroma,
    http://www.isophonics.net/nnls-chroma
 * 
 * Category: Spectral
 * Mode: standard
 */
class LogSpectrum extends BaseAlgorithm
{
    protected string $algorithmName = 'LogSpectrum';
    protected string $mode = 'standard';
    protected string $category = 'Spectral';

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
                "Failed to compute LogSpectrum: " . $e->getMessage(),
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