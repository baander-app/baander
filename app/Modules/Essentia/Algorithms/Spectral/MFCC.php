<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * MFCC


Inputs:

  [vector_real] spectrum - the audio spectrum


Outputs:

  [vector_real] bands - the energies in mel bands
  [vector_real] mfcc - the mel frequency cepstrum coefficients


Parameters:

  dctType:
    integer ∈ [2,3] (default = 2)
    the DCT type

  highFrequencyBound:
    real ∈ (0,inf) (default = 11000)
    the upper bound of the frequency range [Hz]

  inputSize:
    integer ∈ (1,inf) (default = 1025)
    the size of input spectrum

  liftering:
    integer ∈ [0,inf) (default = 0)
    the liftering coefficient. Use '0' to bypass it

  logType:
    string ∈ {natural,dbpow,dbamp,log} (default = "dbamp")
    logarithmic compression type. Use 'dbpow' if working with power and 'dbamp'
    if working with magnitudes

  lowFrequencyBound:
    real ∈ [0,inf) (default = 0)
    the lower bound of the frequency range [Hz]

  normalize:
    string ∈ {unit_sum,unit_tri,unit_max} (default = "unit_sum")
    spectrum bin weights to use for each mel band: 'unit_max' to make each mel
    band vertex equal to 1, 'unit_sum' to make each mel band area equal to 1
    summing the actual weights of spectrum bins, 'unit_area' to make each
    triangle mel band area equal to 1 normalizing the weights of each triangle
    by its bandwidth

  numberBands:
    integer ∈ [1,inf) (default = 40)
    the number of mel-bands in the filter

  numberCoefficients:
    integer ∈ [1,inf) (default = 13)
    the number of output mel coefficients

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  silenceThreshold:
    real ∈ (0,inf) (default = 1.00000001335e-10)
    silence threshold for computing log-energy bands

  type:
    string ∈ {magnitude,power} (default = "power")
    use magnitude or power spectrum

  warpingFormula:
    string ∈ {slaneyMel,htkMel} (default = "htkMel")
    The scale implementation type: 'htkMel' scale from the HTK toolkit [2, 3]
    (default) or 'slaneyMel' scale from the Auditory toolbox [4]

  weighting:
    string ∈ {warping,linear} (default = "warping")
    type of weighting function for determining triangle area


Description:

  This algorithm computes the mel-frequency cepstrum coefficients of a
  spectrum. As there is no standard implementation, the MFCC-FB40 is used by
  default:
    - filterbank of 40 bands from 0 to 11000Hz
    - take the log value of the spectrum energy in each mel band. Bands energy
  values below silence threshold will be clipped to its value before computing
  log-energies
    - DCT of the 40 bands down to 13 mel coefficients
  There is a paper describing various MFCC implementations [1].
  
  The parameters of this algorithm can be configured in order to behave like
  HTK [3] as follows:
    - type = 'magnitude'
    - warpingFormula = 'htkMel'
    - weighting = 'linear'
    - highFrequencyBound = 8000
    - numberBands = 26
    - numberCoefficients = 13
    - normalize = 'unit_max'
    - dctType = 3
    - logType = 'log'
    - liftering = 22
  
  In order to completely behave like HTK the audio signal has to be scaled by
  2^15 before the processing and if the Windowing and FrameCutter algorithms
  are used they should also be configured as follows. 
  
  FrameGenerator:
    - frameSize = 1102
    - hopSize = 441
    - startFromZero = True
    - validFrameThresholdRatio = 1
  
  Windowing:
    - type = 'hamming'
    - size = 1102
    - zeroPadding = 946
    - normalized = False
  
  This algorithm depends on the algorithms MelBands and DCT and therefore
  inherits their parameter restrictions. An exception is thrown if any of these
  restrictions are not met. The input "spectrum" is passed to the MelBands
  algorithm and thus imposes MelBands' input requirements. Exceptions are
  inherited by MelBands as well as by DCT.
  
  IDCT can be used to compute smoothed Mel Bands. In order to do this:
    - compute MFCC
    - smoothedMelBands = 10^(IDCT(MFCC)/20)
  
  Note: The second step assumes that 'logType' = 'dbamp' was used to compute
  MFCCs, otherwise that formula should be changed in order to be consistent.
  
  References:
    [1] T. Ganchev, N. Fakotakis, and G. Kokkinakis, "Comparative evaluation
    of various MFCC implementations on the speaker verification task," in
    International Conference on Speach and Computer (SPECOM’05), 2005,
    vol. 1, pp. 191–194.
  
    [2] Mel-frequency cepstrum - Wikipedia, the free encyclopedia,
    http://en.wikipedia.org/wiki/Mel_frequency_cepstral_coefficient
  
    [3] Young, S. J., Evermann, G., Gales, M. J. F., Hain, T., Kershaw, D.,
    Liu, X., … Woodland, P. C. (2009). The HTK Book (for HTK Version 3.4).
    Construction, (July 2000), 384, https://doi.org/http://htk.eng.cam.ac.uk
  
    [4] Slaney, M. Auditory Toolbox: A MATLAB Toolbox for Auditory Modeling
  Work.
    Technical Report, version 2, Interval Research Corporation, 1998.
 * 
 * Category: Spectral
 * Mode: standard
 */
class MFCC extends BaseAlgorithm
{
    protected string $algorithmName = 'MFCC';
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
                "Failed to compute MFCC: " . $e->getMessage(),
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