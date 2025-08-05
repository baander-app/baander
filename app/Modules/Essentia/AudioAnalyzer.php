<?php

declare(strict_types=1);

namespace App\Modules\Essentia;

use App\Modules\Essentia\Types\AudioVector;use App\Modules\Essentia\Utils\AudioUtils;

class AudioAnalyzer
{
    private AlgorithmFactory $factory;
    private float $lastEnergy = 0;

    public function __construct()
    {
        $this->factory = new AlgorithmFactory();
    }

    /**
     * Perform comprehensive audio analysis
     */
    public function analyze(AudioVector $audio): array
    {
        return [
            'basic' => $this->analyzeBasicFeatures($audio),
            'spectral' => $this->analyzeSpectralFeatures($audio),
            'temporal' => $this->analyzeTemporalFeatures($audio),
            'rhythm' => $this->analyzeRhythmFeatures($audio),
            'tonal' => $this->analyzeTonalFeatures($audio),
        ];
    }

    public function analyzeBasicFeatures(AudioVector $audio): array
    {
        $data = $audio->getData();
        
        return [
            'duration' => count($data) / $audio->getSampleRate(),
            'sample_rate' => $audio->getSampleRate(),
            'length' => $audio->getLength(),
            'rms' => AudioUtils::calculateRMS($data),
            'peaks' => count(AudioUtils::findPeaks($data)),
        ];
    }

    public function analyzeSpectralFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Spectral Centroid - measures the "brightness" of a sound
            $spectralCentroid = $this->factory->create('SpectralCentroid');
            $results['spectral_centroid'] = $spectralCentroid->compute($audio);
        } catch (\Exception $e) {
            $results['spectral_centroid'] = $this->calculateSpectralCentroid($audio);
        }
        
        try {
            // Spectral Rolloff - frequency below which 85% of energy is contained
            $spectralRolloff = $this->factory->create('SpectralRolloff');
            $results['spectral_rolloff'] = $spectralRolloff->compute($audio);
        } catch (\Exception $e) {
            $results['spectral_rolloff'] = $this->calculateSpectralRolloff($audio);
        }
        
        try {
            // MFCC - Mel Frequency Cepstral Coefficients for audio fingerprinting
            $mfcc = $this->factory->create('MFCC');
            $results['mfcc'] = $mfcc->compute($audio);
        } catch (\Exception $e) {
            $results['mfcc'] = $this->calculateMFCC($audio);
        }
        
        try {
            // Chroma - pitch class profiles for harmonic analysis
            $chroma = $this->factory->create('ChromaSTFT');
            $results['chroma'] = $chroma->compute($audio);
        } catch (\Exception $e) {
            $results['chroma'] = $this->calculateChroma($audio);
        }
        
        return $results;
    }

    public function analyzeTemporalFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Zero Crossing Rate - how often signal changes sign (indicates pitch)
            $zcr = $this->factory->create('ZeroCrossingRate');
            $results['zero_crossing_rate'] = $zcr->compute($audio);
        } catch (\Exception $e) {
            $results['zero_crossing_rate'] = $this->calculateZeroCrossingRate($audio);
        }
        
        try {
            // Energy - signal energy over time
            $energy = $this->factory->create('Energy');
            $results['energy'] = $energy->compute($audio);
        } catch (\Exception $e) {
            $results['energy'] = $this->calculateEnergy($audio);
        }
        
        try {
            // Loudness - perceptual loudness measurement
            $loudness = $this->factory->create('Loudness');
            $results['loudness'] = $loudness->compute($audio);
        } catch (\Exception $e) {
            $results['loudness'] = $this->calculateLoudness($audio);
        }
        
        return $results;
    }

    public function analyzeRhythmFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Tempo - beats per minute estimation
            $rhythmExtractor = $this->factory->create('RhythmExtractor');
            $rhythmResult = $rhythmExtractor->compute($audio);
            $results['tempo'] = $rhythmResult['bpm'] ?? null;
            $results['beats'] = $rhythmResult['beats'] ?? null;
        } catch (\Exception $e) {
            $results['tempo'] = $this->estimateTempo($audio);
            $results['beats'] = $this->detectBeats($audio);
        }
        
        try {
            // Onset Rate - how frequently new notes/events start
            $onsetRate = $this->factory->create('OnsetRate');
            $results['onset_rate'] = $onsetRate->compute($audio);
        } catch (\Exception $e) {
            $results['onset_rate'] = $this->calculateOnsetRate($audio);
        }
        
        return $results;
    }

    public function analyzeTonalFeatures(AudioVector $audio): array
    {
        $results = [];
        
        try {
            // Key Detection - musical key estimation
            $keyExtractor = $this->factory->create('Key');
            $keyResult = $keyExtractor->compute($audio);
            $results['key'] = $keyResult['key'] ?? null;
            $results['scale'] = $keyResult['scale'] ?? null;
        } catch (\Exception $e) {
            $results['key'] = $this->estimateKey($audio);
            $results['scale'] = 'unknown';
        }
        
        try {
            // Pitch Detection - fundamental frequency estimation
            $pitchYin = $this->factory->create('PitchYin');
            $results['pitch'] = $pitchYin->compute($audio);
        } catch (\Exception $e) {
            $results['pitch'] = $this->estimatePitch($audio);
        }
        
        try {
            // Harmonic Ratio - ratio of harmonic to total energy
            $harmonicRatio = $this->factory->create('HarmonicRatio');
            $results['harmonic_ratio'] = $harmonicRatio->compute($audio);
        } catch (\Exception $e) {
            $results['harmonic_ratio'] = $this->calculateHarmonicRatio($audio);
        }
        
        return $results;
    }

    public function extractFeature(string $algorithmName, AudioVector $audio, array $parameters = []): array
    {
        $algorithm = $this->factory->create($algorithmName, $parameters);
        return $algorithm->compute($audio);
    }

    public function extractFeatures(array $algorithmNames, AudioVector $audio): array
    {
        $results = [];
        
        foreach ($algorithmNames as $algorithmName) {
            try {
                $results[$algorithmName] = $this->extractFeature($algorithmName, $audio);
            } catch (\Exception $e) {
                $results[$algorithmName] = ['error' => $e->getMessage()];
            }
        }
        
        return $results;
    }

    // Fallback implementations when Essentia algorithms are not available

    private function calculateSpectralCentroid(AudioVector $audio): float
    {
        $data = $audio->getData();
        $spectrum = $this->computeSpectrum($data);
        
        $weightedSum = 0;
        $magnitudeSum = 0;
        
        for ($i = 0; $i < count($spectrum); $i++) {
            $frequency = $i * $audio->getSampleRate() / (2 * count($spectrum));
            $magnitude = abs($spectrum[$i]);
            
            $weightedSum += $frequency * $magnitude;
            $magnitudeSum += $magnitude;
        }
        
        return $magnitudeSum > 0 ? $weightedSum / $magnitudeSum : 0;
    }

    private function calculateSpectralRolloff(AudioVector $audio, float $threshold = 0.85): float
    {
        $data = $audio->getData();
        $spectrum = $this->computeSpectrum($data);
        
        $totalEnergy = array_sum(array_map(fn($x) => $x * $x, $spectrum));
        $targetEnergy = $totalEnergy * $threshold;
        
        $cumulativeEnergy = 0;
        for ($i = 0; $i < count($spectrum); $i++) {
            $cumulativeEnergy += $spectrum[$i] * $spectrum[$i];
            if ($cumulativeEnergy >= $targetEnergy) {
                return $i * $audio->getSampleRate() / (2 * count($spectrum));
            }
        }
        
        return $audio->getSampleRate() / 2; // Nyquist frequency
    }

    private function calculateZeroCrossingRate(AudioVector $audio): float
    {
        $data = $audio->getData();
        $crossings = 0;
        
        for ($i = 1; $i < count($data); $i++) {
            if (($data[$i] >= 0 && $data[$i - 1] < 0) || ($data[$i] < 0 && $data[$i - 1] >= 0)) {
                $crossings++;
            }
        }
        
        return $crossings / (count($data) * 2); // Normalize by length
    }

    private function calculateEnergy(AudioVector $audio): float
    {
        $data = $audio->getData();
        return array_sum(array_map(fn($x) => $x * $x, $data)) / count($data);
    }

    private function calculateLoudness(AudioVector $audio): float
    {
        // Simplified A-weighted loudness approximation
        $rms = AudioUtils::calculateRMS($audio->getData());
        return 20 * log10($rms + 1e-10); // Convert to dB with small epsilon
    }

    private function estimateTempo(AudioVector $audio): ?float
    {
        // Simplified tempo estimation using onset detection
        $onsets = $this->detectOnsets($audio);
        if (count($onsets) < 2) return null;
        
        $intervals = [];
        for ($i = 1; $i < count($onsets); $i++) {
            $intervals[] = $onsets[$i] - $onsets[$i - 1];
        }
        
        if (empty($intervals)) return null;
        
        $avgInterval = array_sum($intervals) / count($intervals);
        return 60.0 / $avgInterval; // Convert to BPM
    }

    private function detectBeats(AudioVector $audio): array
    {
        // Simplified beat detection based on energy peaks
        return $this->detectOnsets($audio);
    }

    private function detectOnsets(AudioVector $audio): array
    {
        $data = $audio->getData();
        $windowSize = 1024;
        $hopSize = 512;
        $onsets = [];
        
        for ($i = 0; $i < count($data) - $windowSize; $i += $hopSize) {
            $window = array_slice($data, $i, $windowSize);
            $energy = array_sum(array_map(fn($x) => $x * $x, $window));
            
            // Simple onset detection based on energy increase
            if ($i > 0 && $energy > $this->lastEnergy * 1.5) {
                $onsets[] = $i / $audio->getSampleRate();
            }
            $this->lastEnergy = $energy;
        }
        
        return $onsets;
    }

    private function calculateOnsetRate(AudioVector $audio): float
    {
        $onsets = $this->detectOnsets($audio);
        $duration = $audio->getLength() / $audio->getSampleRate();
        
        return count($onsets) / $duration; // Onsets per second
    }

    private function estimateKey(AudioVector $audio): ?string
    {
        // Simplified key estimation using chroma features
        $chroma = $this->calculateChroma($audio);
        if (empty($chroma)) return null;
        
        $keys = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        $maxIndex = array_search(max($chroma), $chroma);
        
        return $keys[$maxIndex % 12] ?? null;
    }

    private function estimatePitch(AudioVector $audio): ?float
    {
        // Simplified pitch estimation using autocorrelation
        $data = $audio->getData();
        $sampleRate = $audio->getSampleRate();
        
        // Take a window for analysis
        $windowSize = min(2048, count($data));
        $window = array_slice($data, 0, $windowSize);
        
        $maxCorrelation = 0;
        $bestPeriod = 0;
        
        // Check periods corresponding to reasonable pitch range (80Hz - 1000Hz)
        $minPeriod = $sampleRate / 1000;
        $maxPeriod = $sampleRate / 80;
        
        for ($period = $minPeriod; $period <= $maxPeriod; $period++) {
            $correlation = 0;
            $count = $windowSize - $period;
            
            for ($i = 0; $i < $count; $i++) {
                $correlation += $window[$i] * $window[$i + $period];
            }
            
            if ($correlation > $maxCorrelation) {
                $maxCorrelation = $correlation;
                $bestPeriod = $period;
            }
        }
        
        return $bestPeriod > 0 ? $sampleRate / $bestPeriod : null;
    }

    private function calculateHarmonicRatio(AudioVector $audio): float
    {
        $spectrum = $this->computeSpectrum($audio->getData());
        $pitch = $this->estimatePitch($audio);
        
        if (!$pitch) return 0.0;
        
        $harmonicEnergy = 0;
        $totalEnergy = array_sum(array_map(fn($x) => $x * $x, $spectrum));
        
        // Sum energy at harmonic frequencies
        for ($harmonic = 1; $harmonic <= 10; $harmonic++) {
            $frequency = $pitch * $harmonic;
            $bin = round($frequency * count($spectrum) * 2 / $audio->getSampleRate());
            
            if ($bin < count($spectrum)) {
                $harmonicEnergy += $spectrum[$bin] * $spectrum[$bin];
            }
        }
        
        return $totalEnergy > 0 ? $harmonicEnergy / $totalEnergy : 0.0;
    }

    private function calculateMFCC(AudioVector $audio): array
    {
        // Simplified MFCC calculation (normally requires complex mel-scale processing)
        $spectrum = $this->computeSpectrum($audio->getData());
        
        // Return first 13 coefficients (standard for MFCC)
        return array_slice($spectrum, 0, 13);
    }

    private function calculateChroma(AudioVector $audio): array
    {
        $spectrum = $this->computeSpectrum($audio->getData());
        $chroma = array_fill(0, 12, 0.0); // 12 pitch classes
        
        $sampleRate = $audio->getSampleRate();
        $A4 = 440.0;
        
        for ($i = 1; $i < count($spectrum); $i++) {
            $frequency = $i * $sampleRate / (2 * count($spectrum));
            if ($frequency > 0) {
                $semitones = 12 * log($frequency / $A4) / log(2);
                $pitchClass = ((int)round($semitones) % 12 + 12) % 12;
                $chroma[$pitchClass] += abs($spectrum[$i]);
            }
        }
        
        return $chroma;
    }

    private function computeSpectrum(array $data): array
    {
        $N = count($data);
        $spectrum = [];
        
        for ($k = 0; $k < $N / 2; $k++) {
            $real = 0;
            $imag = 0;
            
            for ($n = 0; $n < $N; $n++) {
                $angle = -2 * M_PI * $k * $n / $N;
                $real += $data[$n] * cos($angle);
                $imag += $data[$n] * sin($angle);
            }
            
            $spectrum[] = sqrt($real * $real + $imag * $imag);
        }
        
        return $spectrum;
    }
}