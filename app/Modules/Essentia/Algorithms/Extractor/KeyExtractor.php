<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Extractor;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * KeyExtractor


Inputs:

  [vector_real] audio - the audio input signal


Outputs:

  [string] key - See Key algorithm documentation
  [string] scale - See Key algorithm documentation
    [real] strength - See Key algorithm documentation


Parameters:

  averageDetuningCorrection:
    bool ∈ {true,false} (default = true)
    shifts a pcp to the nearest tempered bin

  frameSize:
    integer ∈ (0,inf) (default = 4096)
    the framesize for computing tonal features

  hopSize:
    integer ∈ (0,inf) (default = 4096)
    the hopsize for computing tonal features

  hpcpSize:
    integer ∈ [12,inf) (default = 12)
    the size of the output HPCP (must be a positive nonzero multiple of 12)

  maxFrequency:
    real ∈ (0,inf) (default = 3500)
    max frequency to apply whitening to [Hz]

  maximumSpectralPeaks:
    integer ∈ (0,inf) (default = 60)
    the maximum number of spectral peaks

  minFrequency:
    real ∈ (0,inf) (default = 25)
    min frequency to apply whitening to [Hz]

  pcpThreshold:
    real ∈ [0,1] (default = 0.20000000298)
    pcp bins below this value are set to 0

  profileType:
    string ∈ {diatonic,krumhansl,temperley,weichai,tonictriad,temperley2005,thpcp,shaath,gomez,noland,faraldo,pentatonic,edmm,edma,bgate,braw} (default = "bgate")
    the type of polyphic profile to use for correlation calculation

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  spectralPeaksThreshold:
    real ∈ (0,inf) (default = 9.99999974738e-05)
    the threshold for the spectral peaks

  tuningFrequency:
    real ∈ (0,inf) (default = 440)
    the tuning frequency of the input signal

  weightType:
    string ∈ {none,cosine,squaredCosine} (default = "cosine")
    type of weighting function for determining frequency contribution

  windowType:
    string ∈ {hamming,hann,hannnsgcq,triangular,square,blackmanharris62,blackmanharris70,blackmanharris74,blackmanharris92} (default = "hann")
    the window type


Description:

  This algorithm extracts key/scale for an audio signal. It computes HPCP
  frames for the input signal and applies key estimation using the Key
  algorithm.
  
  The algorithm allows tuning correction using two complementary methods:
    - Specify the expected `tuningFrequency` for the HPCP computation. The
  algorithm will adapt the semitone crossover frequencies for computing the
  HPCPs accordingly. If not specified, the default tuning is used. Tuning
  frequency can be estimated in advance using TuningFrequency algorithm.
    - Apply tuning correction posterior to HPCP computation, based on peaks in
  the HPCP distribution (`averageDetuningCorrection`). This is possible when
  hpcpSize > 12.
  
  For more information, see the HPCP and Key algorithms.
 * 
 * Category: Extractor
 * Mode: standard
 */
class KeyExtractor extends BaseAlgorithm
{
    protected string $algorithmName = 'KeyExtractor';
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
                "Failed to compute KeyExtractor: " . $e->getMessage(),
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