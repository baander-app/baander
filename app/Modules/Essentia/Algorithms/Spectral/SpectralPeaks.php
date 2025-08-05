<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * SpectralPeaks


Inputs:

  [vector_real] spectrum - the input spectrum


Outputs:

  [vector_real] frequencies - the frequencies of the spectral peaks [Hz]
  [vector_real] magnitudes - the magnitudes of the spectral peaks


Parameters:

  magnitudeThreshold:
    real ∈ (-inf,inf) (default = 0)
    peaks below this given threshold are not outputted

  maxFrequency:
    real ∈ (0,inf) (default = 5000)
    the maximum frequency of the range to evaluate [Hz]

  maxPeaks:
    integer ∈ [1,inf) (default = 100)
    the maximum number of returned peaks

  minFrequency:
    real ∈ [0,inf) (default = 0)
    the minimum frequency of the range to evaluate [Hz]

  orderBy:
    string ∈ {frequency,magnitude} (default = "frequency")
    the ordering type of the outputted peaks (ascending by frequency or
    descending by magnitude)

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]


Description:

  This algorithm extracts peaks from a spectrum. It is important to note that
  the peak algorithm is independent of an input that is linear or in dB, so one
  has to adapt the threshold to fit with the type of data fed to it. The
  algorithm relies on PeakDetection algorithm which is run with parabolic
  interpolation [1]. The exactness of the peak-searching depends heavily on the
  windowing type. It gives best results with dB input, a blackman-harris 92dB
  window and interpolation set to true. According to [1], spectral peak
  frequencies tend to be about twice as accurate when dB magnitude is used
  rather than just linear magnitude. For further information about the peak
  detection, see the description of the PeakDetection algorithm.
  
  It is recommended that the input "spectrum" be computed by the Spectrum
  algorithm. This algorithm uses PeakDetection. See documentation for possible
  exceptions and input requirements on input "spectrum".
  
  References:
    [1] Peak Detection,
    http://ccrma.stanford.edu/~jos/parshl/Peak_Detection_Steps_3.html
 * 
 * Category: Spectral
 * Mode: standard
 */
class SpectralPeaks extends BaseAlgorithm
{
    protected string $algorithmName = 'SpectralPeaks';
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
                "Failed to compute SpectralPeaks: " . $e->getMessage(),
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