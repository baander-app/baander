<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Extractor;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\AlgorithmException;
use App\Modules\Essentia\Types\AudioVector;

/**
 * LowLevelSpectralExtractor


Inputs:

  [vector_real] signal - the audio input signal


Outputs:

  [vector_vector_real] barkbands - spectral energy at each bark band. See BarkBands alogithm
         [vector_real] barkbands_kurtosis - kurtosis from bark bands. See DistributionShape algorithm documentation
         [vector_real] barkbands_skewness - skewness from bark bands. See DistributionShape algorithm documentation
         [vector_real] barkbands_spread - spread from barkbands. See DistributionShape algorithm documentation
         [vector_real] hfc - See HFC algorithm documentation
  [vector_vector_real] mfcc - See MFCC algorithm documentation
         [vector_real] pitch - See PitchYinFFT algorithm documentation
         [vector_real] pitch_instantaneous_confidence - See PitchYinFFT algorithm documentation
         [vector_real] pitch_salience - See PitchSalience algorithm documentation
         [vector_real] silence_rate_20dB - See SilenceRate algorithm documentation
         [vector_real] silence_rate_30dB - See SilenceRate algorithm documentation
         [vector_real] silence_rate_60dB - See SilenceRate algorithm documentation
         [vector_real] spectral_complexity - See Spectral algorithm documentation
         [vector_real] spectral_crest - See Crest algorithm documentation
         [vector_real] spectral_decrease - See Decrease algorithm documentation
         [vector_real] spectral_energy - See Energy algorithm documentation
         [vector_real] spectral_energyband_low - Energy in band (20,150] Hz. See EnergyBand algorithm documentation
         [vector_real] spectral_energyband_middle_low - Energy in band (150,800] Hz.See EnergyBand algorithm documentation
         [vector_real] spectral_energyband_middle_high - Energy in band (800,4000] Hz. See EnergyBand algorithm documentation
         [vector_real] spectral_energyband_high - Energy in band (4000,20000] Hz. See EnergyBand algorithm documentation
         [vector_real] spectral_flatness_db - See flatnessDB algorithm documentation
         [vector_real] spectral_flux - See Flux algorithm documentation
         [vector_real] spectral_rms - See RMS algorithm documentation
         [vector_real] spectral_rolloff - See RollOff algorithm documentation
         [vector_real] spectral_strongpeak - See StrongPeak algorithm documentation
         [vector_real] zerocrossingrate - See ZeroCrossingRate algorithm documentation
         [vector_real] inharmonicity - See Inharmonicity algorithm documentation
  [vector_vector_real] tristimulus - See Tristimulus algorithm documentation
         [vector_real] oddtoevenharmonicenergyratio - See OddToEvenHarmonicEnergyRatio algorithm documentation


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

  This algorithm extracts all low-level spectral features, which do not require
  an equal-loudness filter for their computation, from an audio signal
 * 
 * Category: Extractor
 * Mode: standard
 */
class LowLevelSpectralExtractor extends BaseAlgorithm
{
    protected string $algorithmName = 'LowLevelSpectralExtractor';
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
                "Failed to compute LowLevelSpectralExtractor: " . $e->getMessage(),
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