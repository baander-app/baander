<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * RhythmTransform


Inputs:

  [vector_vector_real] melBands - the energies in the mel bands


Outputs:

  [vector_vector_real] rhythm - consecutive frames in the rhythm domain


Parameters:

  frameSize:
    integer ∈ (0,inf) (default = 256)
    the frame size to compute the rhythm trasform

  hopSize:
    integer ∈ (0,inf) (default = 32)
    the hop size to compute the rhythm transform


Description:

  This algorithm implements the rhythm transform. It computes a tempogram, a
  representation of rhythmic periodicities in the input signal in the rhythm
  domain, by using FFT similarly to computation of spectrum in the frequency
  domain [1]. Additional features, including rhythmic centroid and a rhythmic
  counterpart of MFCCs, can be derived from this rhythmic representation.
  
  The algorithm relies on a time sequence of frames of Mel bands energies as an
  input (see MelBands), but other types of frequency bands can be used as well
  (see BarkBands, ERBBands, FrequencyBands). For each band, the derivative of
  the frame to frame energy evolution is computed, and the periodicity of the
  resulting signal is computed: the signal is cut into frames of "frameSize"
  size and is analyzed with FFT. For each frame, the obtained power spectrums
  are summed across all bands forming a frame of rhythm transform values.
  
  Quality: experimental (non-reliable, poor accuracy according to tests on
  simple loops, more tests are necessary)
  
  References:
    [1] E. Guaus and P. Herrera, "The rhythm transform: towards a generic
    rhythm description," in International Computer Music Conference
  (ICMC’05),
    2005.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class RhythmTransform extends BaseAlgorithm
{
    protected string $algorithmName = 'RhythmTransform';
    protected string $mode = 'standard';
    protected string $category = 'Rhythm';

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
                "Failed to compute RhythmTransform: " . $e->getMessage(),
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