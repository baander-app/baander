<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\AudioProblems;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PeakDetection


Inputs:

  [vector_real] array - the input array


Outputs:

  [vector_real] positions - the positions of the peaks
  [vector_real] amplitudes - the amplitudes of the peaks


Parameters:

  interpolate:
    bool ∈ {true,false} (default = true)
    boolean flag to enable interpolation

  maxPeaks:
    integer ∈ [1,inf) (default = 100)
    the maximum number of returned peaks

  maxPosition:
    real ∈ (0,inf) (default = 1)
    the maximum value of the range to evaluate

  minPeakDistance:
    real ∈ [0,inf) (default = 0)
    minimum distance between consecutive peaks (0 to bypass this feature)

  minPosition:
    real ∈ [0,inf) (default = 0)
    the minimum value of the range to evaluate

  orderBy:
    string ∈ {position,amplitude} (default = "position")
    the ordering type of the output peaks (ascending by position or descending
    by value)

  range:
    real ∈ (0,inf) (default = 1)
    the input range

  threshold:
    real ∈ (-inf,inf) (default = -1000000)
    peaks below this given threshold are not output


Description:

  This algorithm detects local maxima (peaks) in an array. The algorithm finds
  positive slopes and detects a peak when the slope changes sign and the peak
  is above the threshold.
  It optionally interpolates using parabolic curve fitting.
  When two consecutive peaks are closer than the `minPeakDistance` parameter,
  the smallest one is discarded. A value of 0 bypasses this feature.
  
  Exceptions are thrown if parameter "minPosition" is greater than parameter
  "maxPosition", also if the size of the input array is less than 2 elements.
  
  References:
    [1] Peak Detection,
    http://ccrma.stanford.edu/~jos/parshl/Peak_Detection_Steps_3.html
 * 
 * Category: AudioProblems
 * Mode: standard
 */
class PeakDetection extends BaseAlgorithm
{
    protected string $algorithmName = 'PeakDetection';
    protected string $mode = 'standard';
    protected string $category = 'AudioProblems';

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
                "Failed to compute PeakDetection: " . $e->getMessage(),
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