<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Algorithms\Spectral;

use App\Modules\Essentia\Algorithms\BaseAlgorithm;
use App\Modules\Essentia\Exceptions\{AlgorithmException, ConfigurationException};
use App\Modules\Essentia\Types\AudioVector;
use FFI;

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
                "Failed to compute MFCC: " . $e->getMessage(),
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
                throw new AlgorithmException("Failed to create MFCC algorithm instance");
            }
            
            // Configure algorithm parameters
            $this->configureAlgorithmParameters();
            $this->configured = true;
            
        } catch (\FFI\Exception $e) {
            throw new AlgorithmException("FFI error initializing MFCC: " . $e->getMessage(), 0, $e);
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
                    throw new AlgorithmException('Spectral algorithms expect array or AudioVector input');
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

            case 'MFCC':
                // MFCC computation
                $inputSize = is_array($input) ? count($input) : ($input instanceof AudioVector ? $input->getLength() : 1024);
                $numCoeffs = $this->parameters['numCoeffs'] ?? 13;
                $coefficients = $ffi->new("float[$numCoeffs]");
                
                $result = $ffi->essentia_mfcc($input, $inputSize, $coefficients, $numCoeffs);
                
                if ($result != 0) {
                    throw new AlgorithmException('MFCC computation failed');
                }
                
                $outputs = [];
                for ($i = 0; $i < $numCoeffs; $i++) {
                    $outputs[] = $coefficients[$i];
                }
                break;
            
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
        return ['numCoeffs', 'sampleRate', 'numberBands', 'lowFrequencyBound', 'highFrequencyBound', 'inputSize', 'type', 'weighting', 'warpingFormula', 'logType', 'normalize', 'dctType', 'liftering'];
    }
}