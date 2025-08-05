<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * NoveltyCurve


Inputs:

  [vector_vector_real] frequencyBands - the frequency bands


Outputs:

  [vector_real] novelty - the novelty curve as a single vector


Parameters:

  frameRate:
    real ∈ [1,inf) (default = 344.53125)
    the sampling rate of the input audio

  normalize:
    bool ∈ {true,false} (default = false)
    whether to normalize each band's energy

  weightCurve:
    vector_real (default = [])
    vector containing the weights for each frequency band. Only if
    weightCurveType==supplied

  weightCurveType:
    string ∈ {flat,triangle,inverse_triangle,parabola,inverse_parabola,linear,quadratic,inverse_quadratic,hybrid,supplied} (default = "hybrid")
    the type of weighting to be used for the bands novelty


Description:

  This algorithm computes the "novelty curve" (Grosche & Müller, 2009) onset
  detection function. The algorithm expects as an input a frame-wise sequence
  of frequency-bands energies or spectrum magnitudes as originally proposed in
  [1] (see FrequencyBands and Spectrum algorithms). Novelty in each band (or
  frequency bin) is computed as a derivative between log-compressed energy
  (magnitude) values in consequent frames. The overall novelty value is then
  computed as a weighted sum that can be configured using 'weightCurve'
  parameter. The resulting novelty curve can be used for beat tracking and
  onset detection (see BpmHistogram and Onsets).
  
  Notes:
  
  - Recommended frame/hop size for spectrum computation is 2048/1024 samples
  (44.1 kHz sampling rate) [2].
  - Log compression is applied with C=1000 as in [1].
  - Frequency bands energies (see FrequencyBands) as well as bin magnitudes for
  the whole spectrum can be used as an input. The implementation for the
  original algorithm [2] works with spectrum bin magnitudes for which novelty
  functions are computed separately and are then summarized into bands.
  - In the case if 'weightCurve' is set to 'hybrid' a complex combination of
  flat, quadratic, linear and inverse quadratic weight curves is used. It was
  reported to improve performance of beat tracking in some informal in-house
  experiments (Note: this information is probably outdated).
  
  References:
  
  1. Grosche, P. & Müller, M. (2009). A mid-level representation for capturing
  dominant tempo and pulse information in music recordings. International
  Society for Music Information Retrieval Conference (ISMIR 2009).
  
  2. Tempogram Toolbox (Matlab implementation),
  http://resources.mpi%2Dinf.mpg.de/MIR/tempogramtoolbox
 * 
 * Category: Rhythm
 * Mode: standard
 */
class NoveltyCurve extends BaseAlgorithm
{
    protected string $algorithmName = 'NoveltyCurve';
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
                "Failed to compute NoveltyCurve: " . $e->getMessage(),
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