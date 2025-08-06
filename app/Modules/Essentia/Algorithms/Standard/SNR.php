<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Standard;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\{AlgorithmException, ConfigurationException};
use App\Modules\Essentia\Types\AudioVector;
use FFI;

/**
 * SNR


Inputs:

  [vector_real] frame - the input audio frame


Outputs:

         [real] instantSNR - SNR value for the the current frame
         [real] averagedSNR - averaged SNR through an Exponential Moving Average filter
  [vector_real] spectralSNR - instant SNR for each frequency bin


Parameters:

  MAAlpha:
    real ∈ [0,1] (default = 0.949999988079)
    Alpha coefficient for the EMA SNR estimation [2]

  MMSEAlpha:
    real ∈ [0,1] (default = 0.980000019073)
    Alpha coefficient for the MMSE estimation [1].

  NoiseAlpha:
    real ∈ [0,1] (default = 0.899999976158)
    Alpha coefficient for the EMA noise estimation [2]

  frameSize:
    integer ∈ (1,inf) (default = 512)
    the size of the input frame

  noiseThreshold:
    real ∈ (-inf,0] (default = -40)
    Threshold to detect frames without signal

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the sampling rate of the audio signal [Hz]

  useBroadbadNoiseCorrection:
    bool ∈ {true,false} (default = true)
    flag to apply the -10 * log10(BW) broadband noise correction factor


Description:

  This algorithm computes the SNR of the input audio in a frame-wise manner.
  
  The algorithm assumes that:
  
  - The noise is gaussian.
  - There is a region of noise (without signal) at the beginning of the stream
  in order to estimate the PSD of the noise [1].
  
  Once the noise PSD is estimated, the algorithm relies on the Ephraim-Malah
  [2] recursion to estimate the SNR for each frequency bin.
  
  The algorithm also returns an overall (a single value for the whole spectrum)
  SNR estimation and an averaged overall SNR estimation using Exponential
  Moving Average filtering.
  
  This algorithm throws a warning if less than 15 frames are used to estimate
  the noise PSD.
  
  References:
  
  1. Vaseghi, S. V. (2008). Advanced digital signal processing and noise
  reduction. John Wiley & Sons. Page 336.
  
  2. Ephraim, Y., & Malah, D. (1984). Speech enhancement using a minimum-mean
  square error short-time spectral amplitude estimator. IEEE Transactions on
  acoustics, speech, and signal processing, 32(6), 1109-1121.
 * 
 * Category: Standard
 * Mode: standard
 */
class SNR extends BaseAlgorithm
{
    protected string $algorithmName = 'SNR';
    protected string $mode = 'standard';
    protected string $category = 'Standard';
    
    private ?\FFI\CData $algorithmHandle = null;
    private bool $configured = false;

    public function __destruct()
    {
        if ($this->algorithmHandle) {
            $this->cleanupAlgorithm();
        }
    }

    public function compute($input): array
    {
        try {
            // Lazy initialization of the algorithm
            if (!$this->algorithmHandle) {
                $this->initializeAlgorithm();
            }
            
            // Input validation based on algorithm type
            $this->validateAlgorithmInput($input);
            
            // Convert input to appropriate format
            $processedInput = $this->prepareInput($input);
            
            // Execute the algorithm
            $result = $this->executeAlgorithm($processedInput);
            
            return $this->processOutput($result);
            
        } catch (\Exception $e) {
            throw new AlgorithmException(
                "Failed to compute SNR: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function initializeAlgorithm(): void
    {
        $ffi = $this->essentia->getFFI();
        
        try {
            // Create algorithm instance
            $this->algorithmHandle = $ffi->{$this->getAlgorithmCreateFunction()}();
            
            if (!$this->algorithmHandle) {
                throw new AlgorithmException("Failed to create SNR algorithm instance");
            }
            
            // Configure algorithm parameters
            $this->configureAlgorithmParameters();
            $this->configured = true;
            
        } catch (\FFI\Exception $e) {
            throw new AlgorithmException("FFI error initializing SNR: " . $e->getMessage(), 0, $e);
        }
    }

    private function getAlgorithmCreateFunction(): string
    {
        // Convert algorithm name to C function name
        $functionName = 'essentia_create_' . strtolower($this->algorithmName);
        return $functionName;
    }

    private function configureAlgorithmParameters(): void
    {
        if (empty($this->parameters)) {
            return;
        }
        
        $ffi = $this->essentia->getFFI();
        
        foreach ($this->parameters as $key => $value) {
            try {
                $this->setAlgorithmParameter($ffi, $key, $value);
            } catch (\Exception $e) {
                throw new ConfigurationException("Failed to set parameter '$key': " . $e->getMessage(), 0, $e);
            }
        }
    }

    private function setAlgorithmParameter(FFI $ffi, string $key, $value): void
    {
        // Parameter setting logic based on value type
        switch (gettype($value)) {
            case 'integer':
                $ffi->essentia_algorithm_set_int_parameter($this->algorithmHandle, $key, $value);
                break;
            case 'double':
                $ffi->essentia_algorithm_set_real_parameter($this->algorithmHandle, $key, (float) $value);
                break;
            case 'string':
                $ffi->essentia_algorithm_set_string_parameter($this->algorithmHandle, $key, $value);
                break;
            case 'boolean':
                $ffi->essentia_algorithm_set_bool_parameter($this->algorithmHandle, $key, $value);
                break;
            case 'array':
                $this->setArrayParameter($ffi, $key, $value);
                break;
            default:
                throw new ConfigurationException("Unsupported parameter type for '$key': " . gettype($value));
        }
    }

    private function setArrayParameter(FFI $ffi, string $key, array $value): void
    {
        if (empty($value)) {
            return;
        }
        
        $firstElement = reset($value);
        
        if (is_numeric($firstElement)) {
            // Numeric array
            $size = count($value);
            $cArray = $ffi->new("float[$size]");
            
            for ($i = 0; $i < $size; $i++) {
                $cArray[$i] = (float) $value[$i];
            }
            
            $ffi->essentia_algorithm_set_real_vector_parameter($this->algorithmHandle, $key, $cArray, $size);
        } else {
            // String array
            $size = count($value);
            $cArray = $ffi->new("char*[$size]");
            
            for ($i = 0; $i < $size; $i++) {
                $cArray[$i] = $ffi->new("char[" . (strlen($value[$i]) + 1) . "]");
                FFI::memcpy($cArray[$i], $value[$i], strlen($value[$i]));
            }
            
            $ffi->essentia_algorithm_set_string_vector_parameter($this->algorithmHandle, $key, $cArray, $size);
        }
    }

    private function validateAlgorithmInput($input): void
    {
        // Category-specific input validation
        switch ($this->category) {
            case 'Spectral':
            case 'Temporal':
            case 'Tonal':
                if (!is_array($input) && !($input instanceof AudioVector)) {
                    throw new AlgorithmException('Standard algorithms expect array or AudioVector input');
                }
                break;
                
            case 'Io':
                if (in_array($this->algorithmName, ['AudioLoader', 'MonoLoader', 'EasyLoader'])) {
                    if (!is_string($input)) {
                        throw new AlgorithmException('Loader algorithms expect string file path');
                    }
                    if (!file_exists($input)) {
                        throw new AlgorithmException("Audio file not found: $input");
                    }
                } elseif (in_array($this->algorithmName, ['AudioWriter', 'MonoWriter'])) {
                    if (!($input instanceof AudioVector) && !is_array($input)) {
                        throw new AlgorithmException('Writer algorithms expect AudioVector or array input');
                    }
                }
                break;
                
            case 'Rhythm':
                $this->validateInput($input, 'array');
                break;
                
            case 'Filters':
                $this->validateInput($input, 'array');
                break;
                
            case 'MachineLearning':
                // ML algorithms may have different input requirements
                if (!is_array($input) && !($input instanceof AudioVector)) {
                    throw new AlgorithmException('ML algorithms expect array or AudioVector input');
                }
                break;
                
            case 'Standard':
                // Most flexible category
                if (!is_array($input) && !($input instanceof AudioVector) && !is_numeric($input) && !is_string($input)) {
                    throw new AlgorithmException('Unsupported input type');
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
        
        if (is_array($input)) {
            $ffi = $this->essentia->getFFI();
            $size = count($input);
            $cArray = $ffi->new("float[$size]");
            
            for ($i = 0; $i < $size; $i++) {
                $cArray[$i] = (float) $input[$i];
            }
            
            return $cArray;
        }
        
        return $input;
    }

    private function executeAlgorithm($input): array
    {
        $ffi = $this->essentia->getFFI();
        
        try {
            // Algorithm-specific execution logic
            return $this->executeSpecificAlgorithm($ffi, $input);
            
        } catch (\FFI\Exception $e) {
            throw new AlgorithmException("FFI execution error: " . $e->getMessage(), 0, $e);
        }
    }

    private function executeSpecificAlgorithm(FFI $ffi, $input): array
    {
        // This method contains algorithm-specific execution logic
        $outputs = [];
        
        switch ($this->algorithmName) {

            
            default:
                // Generic execution for unknown algorithms
                $outputs = $this->executeGenericAlgorithm($ffi, $input);
        }
        
        return $outputs;
    }

    private function executeGenericAlgorithm(FFI $ffi, $input): array
    {
        // Generic algorithm execution - assumes single input/output
        try {
            // Prepare output buffers
            $outputSize = $this->estimateOutputSize($input);
            $output = $ffi->new("float[$outputSize]");
            $actualSize = $ffi->new("int");
            
            // Execute algorithm
            $result = $ffi->essentia_algorithm_compute($this->algorithmHandle, $input, $output, $actualSize);
            
            if ($result != 0) {
                throw new AlgorithmException("Algorithm execution failed with code: $result");
            }
            
            // Convert output to PHP array
            $phpOutput = [];
            for ($i = 0; $i < $actualSize->cdata; $i++) {
                $phpOutput[] = $output[$i];
            }
            
            return $phpOutput;
            
        } catch (\Exception $e) {
            throw new AlgorithmException("Generic execution failed: " . $e->getMessage(), 0, $e);
        }
    }

    private function estimateOutputSize($input): int
    {
        // Estimate output size based on algorithm category and input
        switch ($this->category) {
            case 'Spectral':
                // Spectral algorithms often output half the input size (FFT)
                return is_array($input) ? count($input) / 2 : 1024;
                
            case 'Temporal':
            case 'Tonal':
                // Temporal/tonal algorithms often output similar or smaller size
                return is_array($input) ? count($input) : 1024;
                
            case 'Stats':
                // Statistical algorithms often output small fixed sizes
                return 16;
                
            case 'Rhythm':
                // Rhythm algorithms vary widely
                return 256;
                
            default:
                // Conservative default
                return is_array($input) ? count($input) : 1024;
        }
    }

    private function processOutput(array $result): array
    {
        // Post-process the output based on algorithm characteristics
        switch ($this->category) {
            case 'Spectral':
                return $this->processSpectralOutput($result);
                
            case 'Temporal':
                return $this->processTemporalOutput($result);
                
            case 'Tonal':
                return $this->processTonalOutput($result);
                
            case 'Rhythm':
                return $this->processRhythmOutput($result);
                
            case 'Stats':
                return $this->processStatsOutput($result);
                
            default:
                // Return as-is for unknown categories
                return $result;
        }
    }

    private function processSpectralOutput(array $result): array
    {
        // Process spectral algorithm outputs
        switch ($this->algorithmName) {
            case 'SpectralCentroid':
            case 'SpectralRolloff':
                return ['value' => $result[0] ?? 0.0];
                
            case 'MFCC':
                return ['coefficients' => $result];
                
            case 'MelBands':
            case 'BarkBands':
                return ['bands' => $result];
                
            case 'SpectralPeaks':
                // Usually returns frequencies and magnitudes
                $half = count($result) / 2;
                return [
                    'frequencies' => array_slice($result, 0, $half),
                    'magnitudes' => array_slice($result, $half)
                ];
                
            default:
                return ['spectrum' => $result];
        }
    }

    private function processTemporalOutput(array $result): array
    {
        switch ($this->algorithmName) {
            case 'Energy':
            case 'RMS':
            case 'ZeroCrossingRate':
                return ['value' => $result[0] ?? 0.0];
                
            case 'Envelope':
                return ['envelope' => $result];
                
            default:
                return ['values' => $result];
        }
    }

    private function processTonalOutput(array $result): array
    {
        switch ($this->algorithmName) {
            case 'PitchYin':
            case 'PitchYinFFT':
                return [
                    'pitch' => $result[0] ?? 0.0,
                    'confidence' => $result[1] ?? 0.0
                ];
                
            case 'Key':
                return [
                    'key' => $result[0] ?? 'C',
                    'scale' => $result[1] ?? 'major',
                    'strength' => $result[2] ?? 0.0
                ];
                
            case 'HPCP':
            case 'Chromagram':
                return ['chroma' => $result];
                
            default:
                return ['tonal_features' => $result];
        }
    }

    private function processRhythmOutput(array $result): array
    {
        switch ($this->algorithmName) {
            case 'RhythmExtractor':
                return [
                    'bpm' => $result[0] ?? 0.0,
                    'beats' => array_slice($result, 1) ?? []
                ];
                
            case 'OnsetDetection':
                return ['onsets' => $result];
                
            case 'TempoTap':
                return ['tempo' => $result[0] ?? 0.0];
                
            default:
                return ['rhythm_features' => $result];
        }
    }

    private function processStatsOutput(array $result): array
    {
        switch ($this->algorithmName) {
            case 'Mean':
            case 'Variance':
            case 'Centroid':
                return ['value' => $result[0] ?? 0.0];
                
            case 'DistributionShape':
                return [
                    'spread' => $result[0] ?? 0.0,
                    'skewness' => $result[1] ?? 0.0,
                    'kurtosis' => $result[2] ?? 0.0
                ];
                
            default:
                return ['statistics' => $result];
        }
    }

    private function cleanupAlgorithm(): void
    {
        if ($this->algorithmHandle) {
            try {
                $ffi = $this->essentia->getFFI();
                $ffi->essentia_delete_algorithm($this->algorithmHandle);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
            $this->algorithmHandle = null;
        }
    }

    protected function isValidParameter(string $parameter): bool
    {
        // Algorithm-specific parameter validation
        $validParams = $this->getValidParameters();
        return empty($validParams) || in_array($parameter, $validParams);
    }

    private function getValidParameters(): array
    {
        // Return algorithm-specific valid parameters
        return ['frameSize', 'noiseThreshold', 'useBroadbandNoise'];
    }
}