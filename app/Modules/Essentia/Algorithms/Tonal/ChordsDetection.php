<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * ChordsDetection


Inputs:

  [vector_vector_real] pcp - the pitch class profile from which to detect the chord


Outputs:

  [vector_string] chords - the resulting chords, from A to G
    [vector_real] strength - the strength of the chord


Parameters:

  hopSize:
    integer ∈ (0,inf) (default = 2048)
    the hop size with which the input PCPs were computed

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  windowSize:
    real ∈ (0,inf) (default = 2)
    the size of the window on which to estimate the chords [s]


Description:

  This algorithm estimates chords given an input sequence of harmonic pitch
  class profiles (HPCPs). It finds the best matching major or minor triad and
  outputs the result as a string (e.g. A#, Bm, G#m, C). The following note
  names are used in the output:
  "A", "Bb", "B", "C", "C#", "D", "Eb", "E", "F", "F#", "G", "Ab".
  Note:
    - The algorithm assumes that the sequence of the input HPCP frames has been
  computed with framesize = 2*hopsize
    - The algorithm estimates a sequence of chord values corresponding to the
  input HPCP frames (one chord value for each frame, estimated using a temporal
  window of HPCPs centered at that frame).
  
  Quality: experimental (prone to errors, algorithm needs improvement)
  
  References:
    [1] E. Gómez, "Tonal Description of Polyphonic Audio for Music Content
    Processing," INFORMS Journal on Computing, vol. 18, no. 3, pp. 294–304,
    2006.
  
    [2] D. Temperley, "What's key for key? The Krumhansl-Schmuckler
    key-finding algorithm reconsidered", Music Perception vol. 17, no. 1,
    pp. 65-100, 1999.
 * 
 * Category: Tonal
 * Mode: standard
 */
class ChordsDetection extends BaseAlgorithm
{
    protected string $algorithmName = 'ChordsDetection';
    protected string $mode = 'standard';
    protected string $category = 'Tonal';

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
                "Failed to compute ChordsDetection: " . $e->getMessage(),
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