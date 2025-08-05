<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * HFC


Inputs:

  [vector_real] spectrum - the input audio spectrum


Outputs:

  [real] hfc - the high-frequency coefficient


Parameters:

  sampleRate:
    real ∈ (0,inf] (default = 44100)
    the sampling rate of the audio signal [Hz]

  type:
    string ∈ {Masri,Jensen,Brossier} (default = "Masri")
    the type of HFC coefficient to be computed


Description:

  This algorithm computes the High Frequency Content of a spectrum. It can be
  computed according to the following techniques:
    - 'Masri' (default) which does: sum |X(n)|^2*k,
    - 'Jensen' which does: sum |X(n)|*k^2
    - 'Brossier' which does: sum |X(n)|*k
  
  Exception is thrown for empty input spectra.
  
  References:
    [1] P. Masri and A. Bateman, “Improved modelling of attack transients in
    music analysis-resynthesis,” in Proceedings of the International
    Computer Music Conference, 1996, pp. 100–103.
  
    [2] K. Jensen and T. H. Andersen, “Beat estimation on the beat,” in
    Applications of Signal Processing to Audio and Acoustics, 2003 IEEE
    Workshop on., 2003, pp. 87–90.
  
    [3] High frequency content measure - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/High_Frequency_Content_measure
 * 
 * Category: Spectral
 * Mode: standard
 */
class HFC extends BaseAlgorithm
{
    protected string $algorithmName = 'HFC';
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
                "Failed to compute HFC: " . $e->getMessage(),
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