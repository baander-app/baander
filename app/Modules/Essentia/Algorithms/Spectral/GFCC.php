<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * GFCC


Inputs:

  [vector_real] spectrum - the audio spectrum


Outputs:

  [vector_real] bands - the energies in ERB bands
  [vector_real] gfcc - the gammatone feature cepstrum coefficients


Parameters:

  dctType:
    integer ∈ [2,3] (default = 2)
    the DCT type

  highFrequencyBound:
    real ∈ (0,inf) (default = 22050)
    the upper bound of the frequency range [Hz]

  inputSize:
    integer ∈ (1,inf) (default = 1025)
    the size of input spectrum

  logType:
    string ∈ {natural,dbpow,dbamp,log} (default = "dbamp")
    logarithmic compression type. Use 'dbpow' if working with power and 'dbamp'
    if working with magnitudes

  lowFrequencyBound:
    real ∈ [0,inf) (default = 40)
    the lower bound of the frequency range [Hz]

  numberBands:
    integer ∈ [1,inf) (default = 40)
    the number of bands in the filter

  numberCoefficients:
    integer ∈ [1,inf) (default = 13)
    the number of output cepstrum coefficients

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  silenceThreshold:
    real ∈ (0,inf) (default = 1.00000001335e-10)
    silence threshold for computing log-energy bands

  type:
    string ∈ {magnitude,power} (default = "power")
    use magnitude or power spectrum


Description:

  This algorithm computes the Gammatone-frequency cepstral coefficients of a
  spectrum. This is an equivalent of MFCCs, but using a gammatone filterbank
  (ERBBands) scaled on an Equivalent Rectangular Bandwidth (ERB) scale.
  
  References:
    [1] Y. Shao, Z. Jin, D. Wang, and S. Srinivasan, "An auditory-based feature
    for robust speech recognition," in IEEE International Conference on
    Acoustics, Speech, and Signal Processing (ICASSP’09), 2009,
    pp. 4625-4628.
 * 
 * Category: Spectral
 * Mode: standard
 */
class GFCC extends BaseAlgorithm
{
    protected string $algorithmName = 'GFCC';
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
                "Failed to compute GFCC: " . $e->getMessage(),
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