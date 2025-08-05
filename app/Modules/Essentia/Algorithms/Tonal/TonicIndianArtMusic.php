<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Tonal;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * TonicIndianArtMusic


Inputs:

  [vector_real] signal - the input signal


Outputs:

  [real] tonic - the estimated tonic frequency [Hz]


Parameters:

  binResolution:
    real ∈ (0,inf) (default = 10)
    salience function bin resolution [cents]

  frameSize:
    integer ∈ (0,inf) (default = 2048)
    the frame size for computing pitch saliecnce

  harmonicWeight:
    real ∈ (0,1) (default = 0.850000023842)
    harmonic weighting parameter (weight decay ratio between two consequent
    harmonics, =1 for no decay)

  hopSize:
    integer ∈ (0,inf) (default = 512)
    the hop size with which the pitch salience function was computed

  magnitudeCompression:
    real ∈ (0,1] (default = 1)
    magnitude compression parameter (=0 for maximum compression, =1 for no
    compression)

  magnitudeThreshold:
    real ∈ [0,inf) (default = 40)
    peak magnitude threshold (maximum allowed difference from the highest peak
    in dBs)

  maxTonicFrequency:
    real ∈ [0,inf) (default = 375)
    the maximum allowed tonic frequency [Hz]

  minTonicFrequency:
    real ∈ [0,inf) (default = 100)
    the minimum allowed tonic frequency [Hz]

  numberHarmonics:
    integer ∈ [1,inf) (default = 20)
    number of considered hamonics

  numberSaliencePeaks:
    integer ∈ [1,15] (default = 5)
    number of top peaks of the salience function which should be considered for
    constructing histogram

  referenceFrequency:
    real ∈ (0,inf) (default = 55)
    the reference frequency for Hertz to cent convertion [Hz], corresponding to
    the 0th cent bin

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm estimates the tonic frequency of the lead artist in Indian art
  music. It uses multipitch representation of the audio signal (pitch salience)
  to compute a histogram using which the tonic is identified as one of its
  peak. The decision is made based on the distance between the prominent peaks,
  the classification is done using a decision tree. An empty input signal will
  throw an exception. An exception will also be thrown if no predominant pitch
  salience peaks are detected within the maxTonicFrequency to minTonicFrequency
  range. 
  
  References:
    [1] J. Salamon, S. Gulati, and X. Serra, "A Multipitch Approach to Tonic
    Identification in Indian Classical Music," in International Society for
    Music Information Retrieval Conference (ISMIR’12), 2012.
 * 
 * Category: Tonal
 * Mode: standard
 */
class TonicIndianArtMusic extends BaseAlgorithm
{
    protected string $algorithmName = 'TonicIndianArtMusic';
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
                "Failed to compute TonicIndianArtMusic: " . $e->getMessage(),
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