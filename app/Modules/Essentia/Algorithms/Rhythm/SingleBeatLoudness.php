<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Rhythm;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\{AlgorithmException, ConfigurationException};
use App\Modules\Essentia\Types\AudioVector;
use FFI;

/**
 * SingleBeatLoudness


Inputs:

  [vector_real] beat - audio segement containing a beat


Outputs:

         [real] loudness - the beat's energy across the whole spectrum
  [vector_real] loudnessBandRatio - the beat's energy ratio for each band


Parameters:

  beatDuration:
    real ∈ (0,inf) (default = 0.0500000007451)
    window size for the beat's energy computation (the window starts at the
    onset) [s]

  beatWindowDuration:
    real ∈ (0,inf) (default = 0.10000000149)
    window size for the beat's onset detection [s]

  frequencyBands:
    vector_real (default = [0, 200, 400, 800, 1600, 3200, 22000])
    frequency bands

  onsetStart:
    string ∈ {sumEnergy,peakEnergy} (default = "sumEnergy")
    criteria for finding the start of the beat

  sampleRate:
    real ∈ (0,inf) (default = 44100)
    the audio sampling rate [Hz]


Description:

  This algorithm computes the spectrum energy of a single beat across the whole
  frequency range and on each specified frequency band given an audio segment.
  It detects the onset of the beat within the input segment, computes spectrum
  on a window starting on this onset, and estimates energy (see Energy and
  EnergyBandRatio algorithms). The frequency bands used by default are: 0-200
  Hz, 200-400 Hz, 400-800 Hz, 800-1600 Hz, 1600-3200 Hz, 3200-22000Hz,
  following E. Scheirer [1].
  
  This algorithm throws an exception either when parameter beatDuration is
  larger than beatWindowSize or when the size of the input beat segment is less
  than beatWindowSize plus beatDuration.
  
  References:
    [1] E. D. Scheirer, "Tempo and beat analysis of acoustic musical signals,"
    The Journal of the Acoustical Society of America, vol. 103, p. 588, 1998.
 * 
 * Category: Rhythm
 * Mode: standard
 */
class SingleBeatLoudness extends BaseAlgorithm
{
    protected string $algorithmName = 'SingleBeatLoudness';
    protected string $mode = 'standard';
    protected string $category = 'Rhythm';
    
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
                "Failed to compute SingleBeatLoudness: " . $e->getMessage(),
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
                throw new AlgorithmException("Failed to create SingleBeatLoudness algorithm instance");
            }
            
            // Configure algorithm parameters
            $this->configureAlgorithmParameters();
            $this->configured = true;
            
        } catch (\FFI\Exception $e) {
            throw new AlgorithmException("FFI error initializing SingleBeatLoudness: " . $e->getMessage(), 0, $e);
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
                    throw new AlgorithmException('Rhythm algorithms expect array or AudioVector input');
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
        return [];
    }
}