<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Extractor;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * LowLevelSpectralEqloudExtractor


Inputs:

  [vector_real] signal - the input audio signal


Outputs:

         [vector_real] dissonance - See Dissonance algorithm documentation
  [vector_vector_real] sccoeffs - See SpectralContrast algorithm documentation
  [vector_vector_real] scvalleys - See SpectralContrast algorithm documentation
         [vector_real] spectral_centroid - See Centroid algorithm documentation
         [vector_real] spectral_kurtosis - See DistributionShape algorithm documentation
         [vector_real] spectral_skewness - See DistributionShape algorithm documentation
         [vector_real] spectral_spread - See DistributionShape algorithm documentation


Parameters:

  frameSize:
    integer ∈ (0,inf) (default = 2048)
    the frame size for computing low level features

  hopSize:
    integer ∈ (0,inf) (default = 1024)
    the hop size for computing low level features

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate


Description:

  This algorithm extracts a set of level spectral features for which it is
  recommended to apply a preliminary equal-loudness filter over an input audio
  signal (according to the internal evaluations conducted at Music Technology
  Group). To this end, you are expected to provide the output of EqualLoudness
  algorithm as an input for this algorithm. Still, you are free to provide an
  unprocessed audio input in the case you want to compute these features
  without equal-loudness filter.
  
  Note that at present we do not dispose any reference to justify the necessity
  of equal-loudness filter. Our recommendation is grounded on internal
  evaluations conducted at Music Technology Group that have shown the increase
  in numeric robustness as a function of the audio encoders used (mp3, ogg,
  ...) for these features.
 * 
 * Category: Extractor
 * Mode: standard
 */
class LowLevelSpectralEqloudExtractor extends BaseAlgorithm
{
    protected string $algorithmName = 'LowLevelSpectralEqloudExtractor';
    protected string $mode = 'standard';
    protected string $category = 'Extractor';

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
                "Failed to compute LowLevelSpectralEqloudExtractor: " . $e->getMessage(),
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