<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * PitchYinFFT


Inputs:

  [vector_real] spectrum - the input spectrum (preferably created with a hann window)


Outputs:

  [real] pitch - detected pitch [Hz]
  [real] pitchConfidence - confidence with which the pitch was detected [0,1]


Parameters:

  frameSize:
    integer ∈ [2,inf) (default = 2048)
    number of samples in the input spectrum

  interpolate:
    bool ∈ {true,false} (default = true)
    boolean flag to enable interpolation

  maxFrequency:
    real ∈ (0,inf) (default = 22050)
    the maximum allowed frequency [Hz]

  minFrequency:
    real ∈ (0,inf) (default = 20)
    the minimum allowed frequency [Hz]

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    sampling rate of the input spectrum [Hz]

  tolerance:
    real ∈ [0,1] (default = 1)
    tolerance for peak detection

  weighting:
    string ∈ {custom,A,B,C,D,Z} (default = "custom")
    string to assign a weighting function


Description:

  This algorithm estimates the fundamental frequency given the spectrum of a
  monophonic music signal. It is an implementation of YinFFT algorithm [1],
  which is an optimized version of Yin algorithm for computation in the
  frequency domain. It is recommended to window the input spectrum with a Hann
  window. The raw spectrum can be computed with the Spectrum algorithm.
  
  An exception is thrown if an empty spectrum is provided.
  
  Please note that if "pitchConfidence" is zero, "pitch" is undefined and
  should not be used for other algorithms.
  Also note that a null "pitch" is never ouput by the algorithm and that
  "pitchConfidence" must always be checked out.
  
  References:
    [1] P. M. Brossier, "Automatic Annotation of Musical Audio for Interactive
    Applications,” QMUL, London, UK, 2007.
  
    [2] Pitch detection algorithm - Wikipedia, the free encyclopedia
    http://en.wikipedia.org/wiki/Pitch_detection_algorithm
 * 
 * Category: Spectral
 * Mode: standard
 */
class PitchYinFFT extends BaseAlgorithm
{
    protected string $algorithmName = 'PitchYinFFT';
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
                "Failed to compute PitchYinFFT: " . $e->getMessage(),
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