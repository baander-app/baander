<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TriangularBarkBands


Inputs:

  [vector_real] spectrum - the audio spectrum


Outputs:

  [vector_real] bands - the energy in bark bands


Parameters:

  highFrequencyBound:
    real ∈ [0,inf) (default = 22050)
    an upper-bound limit for the frequencies to be included in the bands

  inputSize:
    integer ∈ (1,inf) (default = 1025)
    the size of the spectrum

  log:
    bool ∈ {true,false} (default = false)
    compute log-energies (log2 (1 + energy))

  lowFrequencyBound:
    real ∈ [0,inf) (default = 0)
    a lower-bound limit for the frequencies to be included in the bands

  normalize:
    string ∈ {unit_sum,unit_max} (default = "unit_sum")
    'unit_max' makes the vertex of all the triangles equal to 1, 'unit_sum'
    makes the area of all the triangles equal to 1

  numberBands:
    integer ∈ (1,inf) (default = 24)
    the number of output bands

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sample rate

  type:
    string ∈ {magnitude,power} (default = "power")
    'power' to output squared units, 'magnitude' to keep it as the input

  weighting:
    string ∈ {warping,linear} (default = "warping")
    type of weighting function for determining triangle area


Description:

  This algorithm computes energy in the bark bands of a spectrum. It is
  different to the regular BarkBands algorithm in that is more configurable so
  that it can be used in the BFCC algorithm to produce output similar to
  Rastamat (http://www.ee.columbia.edu/ln/rosa/matlab/rastamat/)
  See the BFCC algorithm documentation for more information as to why you might
  want to choose this over Mel frequency analysis
  It is recommended that the input "spectrum" be calculated by the Spectrum
  algorithm.
 * 
 * Category: Spectral
 * Mode: standard
 */
class TriangularBarkBands extends BaseAlgorithm
{
    protected string $algorithmName = 'TriangularBarkBands';
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
                "Failed to compute TriangularBarkBands: " . $e->getMessage(),
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