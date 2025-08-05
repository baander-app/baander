<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PitchSalienceFunctionPeaks


Inputs:

  [vector_real] salienceFunction - the array of salience function values corresponding to cent frequency bins


Outputs:

  [vector_real] salienceBins - the cent bins corresponding to salience function peaks
  [vector_real] salienceValues - the values of salience function peaks


Parameters:

  binResolution:
    real ∈ (0,inf) (default = 10)
    salience function bin resolution [cents]

  maxFrequency:
    real ∈ [0,inf) (default = 1760)
    the maximum frequency to evaluate (ignore peaks above) [Hz]

  minFrequency:
    real ∈ [0,inf) (default = 55)
    the minimum frequency to evaluate (ignore peaks below) [Hz]

  referenceFrequency:
    real ∈ (0,inf) (default = 55)
    the reference frequency for Hertz to cent convertion [Hz], corresponding to
    the 0th cent bin


Description:

  This algorithm computes the peaks of a given pitch salience function.
  
  This algorithm is intended to receive its "salienceFunction" input from the
  PitchSalienceFunction algorithm. The peaks are detected using PeakDetection
  algorithm. The outputs are two arrays of bin numbers and salience values
  corresponding to the peaks.
  
  References:
    [1] Salamon, J., & Gómez E. (2012).  Melody Extraction from Polyphonic
  Music Signals using Pitch Contour Characteristics.
        IEEE Transactions on Audio, Speech and Language Processing. 20(6),
  1759-1770.
 * 
 * Category: Tonal
 * Mode: standard
 */
class PitchSalienceFunctionPeaks extends BaseAlgorithm
{
    protected string $algorithmName = 'PitchSalienceFunctionPeaks';
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
                "Failed to compute PitchSalienceFunctionPeaks: " . $e->getMessage(),
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