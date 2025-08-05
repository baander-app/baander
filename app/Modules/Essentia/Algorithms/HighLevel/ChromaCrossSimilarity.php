<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\HighLevel;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * ChromaCrossSimilarity


Inputs:

  [vector_vector_real] queryFeature - frame-wise chromagram of the query song (e.g., a HPCP)
  [vector_vector_real] referenceFeature - frame-wise chromagram of the reference song (e.g., a HPCP)


Outputs:

  [vector_vector_real] csm - 2D binary cross-similarity matrix of the query and reference features


Parameters:

  binarizePercentile:
    real ∈ [0,1] (default = 0.0949999988079)
    maximum percent of distance values to consider as similar in each row and
    each column

  frameStackSize:
    integer ∈ [0,inf) (default = 9)
    number of input frames to stack together and treat as a feature vector for
    similarity computation. Choose 'frameStackSize=1' to use the original input
    frames without stacking

  frameStackStride:
    integer ∈ [1,inf) (default = 1)
    stride size to form a stack of frames (e.g., 'frameStackStride'=1 to use
    consecutive frames; 'frameStackStride'=2 for using every second frame)

  noti:
    integer ∈ [0,inf) (default = 12)
    number of circular shifts to be checked for Optimal Transposition Index [1]

  oti:
    bool ∈ {true,false} (default = true)
    whether to transpose the key of the reference song to the query song by
    Optimal Transposition Index [1]

  otiBinary:
    bool ∈ {true,false} (default = false)
    whether to use the OTI-based chroma binary similarity method [3]

  streaming:
    bool ∈ {true,false} (default = false)
    whether to accumulate the input 'queryFeature' in the euclidean similarity
    matrix calculation on each compute() method call


Description:

  This algorithm computes a binary cross similarity matrix from two chromagam
  feature vectors of a query and reference song.
  
  With default parameters, this algorithm computes cross-similarity of two
  given input chromagrams as described in [2].
  
  Use HPCP algorithm for computing the chromagram with default parameters of
  this algorithm for the best results.
  
  If parameter 'oti=True', the algorithm transpose the reference song
  chromagram by optimal transposition index as described in [1].
  
  If parameter 'otiBinary=True', the algorithm computes the binary
  cross-similarity matrix based on optimal transposition index between each
  feature pairs instead of euclidean distance as described in [3].
  
  The input chromagram should be in the shape (n_frames, numbins), where
  'n_frames' is number of frames and 'numbins' for the number of bins in the
  chromagram. An exception is thrown otherwise.
  
  An exception is also thrown if either one of the input chromagrams are empty.
  
  While param 'streaming=True', the algorithm accumulates the input
  'queryFeature' in the pairwise similarity matrix calculation on each call of
  compute() method. You can reset it using the reset() method.
  
  References:
  
  [1] Serra, J., Gómez, E., & Herrera, P. (2008). Transposing chroma
  representations to a common key, IEEE Conference on The Use of Symbols to
  Represent Music and Multimedia Objects.
  
  [2] Serra, J., Serra, X., & Andrzejak, R. G. (2009). Cross recurrence
  quantification for cover song identification.New Journal of Physics.
  
  [3] Serra, Joan, et al. Chroma binary similarity and local alignment applied
  to cover song identification. IEEE Transactions on Audio, Speech, and
  Language Processing 16.6 (2008).
 * 
 * Category: HighLevel
 * Mode: standard
 */
class ChromaCrossSimilarity extends BaseAlgorithm
{
    protected string $algorithmName = 'ChromaCrossSimilarity';
    protected string $mode = 'standard';
    protected string $category = 'HighLevel';

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
                "Failed to compute ChromaCrossSimilarity: " . $e->getMessage(),
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