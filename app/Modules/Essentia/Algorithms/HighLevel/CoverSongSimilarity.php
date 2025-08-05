<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\HighLevel;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * CoverSongSimilarity


Inputs:

  [vector_vector_real] inputArray -  a 2D binary cross-similarity matrix between two audio chroma vectors (query vs reference song) (refer 'ChromaCrossSimilarity' algorithm').


Outputs:

  [vector_vector_real] scoreMatrix - a 2D smith-waterman alignment score matrix from the input binary cross-similarity matrix
                [real] distance - cover song similarity distance between the query and reference song from the input similarity matrix. Either 'asymmetric' (as described in [2]) or 'symmetric' (maximum score in the alignment score matrix).


Parameters:

  alignmentType:
    string ∈ {serra09,chen17} (default = "serra09")
    choose either one of the given local-alignment constraints for
    smith-waterman algorithm as described in [2] or [3] respectively.

  disExtension:
    real ∈ [0,inf) (default = 0.5)
    penalty for disruption extension

  disOnset:
    real ∈ [0,inf) (default = 0.5)
    penalty for disruption onset

  distanceType:
    string ∈ {asymmetric,symmetric} (default = "asymmetric")
    choose the type of distance. By default the algorithm outputs a asymmetric
    distance which is obtained by normalising the maximum score in the
    alignment score matrix with length of reference song


Description:

  This algorithm computes a cover song similiarity measure from a binary cross
  similarity matrix input between two chroma vectors of a query and reference
  song using various alignment constraints of smith-waterman local-alignment
  algorithm.
  
  This algorithm expects to recieve the binary similarity matrix input from
  essentia 'ChromaCrossSimilarity' algorithm or essentia
  'CrossSimilarityMatrix' with parameter 'binarize=True'.
  
  The algorithm provides two different allignment contraints for computing the
  smith-waterman score matrix (check references).
  
  Exceptions are thrown if the input similarity matrix is not binary or empty.
  
  References:
  
  [1] Smith-Waterman algorithm (Wikipedia,
  https://en.wikipedia.org/wiki/Smith%E2%80%93Waterman_algorithm).
  
  [2] Serra, J., Serra, X., & Andrzejak, R. G. (2009). Cross recurrence
  quantification for cover song identification.New Journal of Physics.
  
  [3] Chen, N., Li, W., & Xiao, H. (2017). Fusing similarity functions for
  cover song identification. Multimedia Tools and Applications.
 * 
 * Category: HighLevel
 * Mode: standard
 */
class CoverSongSimilarity extends BaseAlgorithm
{
    protected string $algorithmName = 'CoverSongSimilarity';
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
                "Failed to compute CoverSongSimilarity: " . $e->getMessage(),
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