<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\MachineLearning;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SBic


Inputs:

  [matrix_real] features - extracted features matrix (rows represent features, and columns represent frames of audio)


Outputs:

  [vector_real] segmentation - a list of frame indices that indicate where a segment of audio begins/ends (the indices of the first and last frame are also added to the list at the beginning and end, respectively)


Parameters:

  cpw:
    real ∈ [0,inf) (default = 1.5)
    complexity penalty weight

  inc1:
    integer ∈ [1,inf) (default = 60)
    first pass increment [frames]

  inc2:
    integer ∈ [1,inf) (default = 20)
    second pass increment [frames]

  minLength:
    integer ∈ [1,inf) (default = 10)
    minimum length of a segment [frames]

  size1:
    integer ∈ [1,inf) (default = 300)
    first pass window size [frames]

  size2:
    integer ∈ [1,inf) (default = 200)
    second pass window size [frames]


Description:

  This algorithm segments audio using the Bayesian Information Criterion given
  a matrix of frame features. The algorithm searches homogeneous segments for
  which the feature vectors have the same probability distribution based on the
  implementation in [1]. The input matrix is assumed to have features along
  dim1 (horizontal) while frames along dim2 (vertical).
  
  The segmentation is done in three phases: coarse segmentation, fine
  segmentation and segment validation. The first phase uses parameters 'size1'
  and 'inc1' to perform BIC segmentation. The second phase uses parameters
  'size2' and 'inc2' to perform a local search for segmentation around the
  segmentation done by the first phase. Finally, the validation phase verifies
  that BIC differentials at segmentation points are positive as well as filters
  out any segments that are smaller than 'minLength'.
  
  Because this algorithm takes as input feature vectors of frames, all units
  are in terms of frames. For example, if a 44100Hz audio signal is segmented
  as [0, 99, 199] with a frame size of 1024 and a hopsize of 512, this means,
  in the time domain, that the audio signal is segmented at [0s, 99*512/44100s,
  199*512/44100s].
  
  An exception is thrown if the input only contains one frame of features (i.e.
  second dimension is less than 2).
  
  References:
    [1] Audioseg, http://audioseg.gforge.inria.fr
  
    [2] G. Gravier, M. Betser, and M. Ben, Audio Segmentation Toolkit,
    release 1.2, 2010. Available online:
    https://gforge.inria.fr/frs/download.php/25187/audioseg-1.2.pdf
 * 
 * Category: MachineLearning
 * Mode: standard
 */
class SBic extends BaseAlgorithm
{
    protected string $algorithmName = 'SBic';
    protected string $mode = 'standard';
    protected string $category = 'MachineLearning';

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
                "Failed to compute SBic: " . $e->getMessage(),
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