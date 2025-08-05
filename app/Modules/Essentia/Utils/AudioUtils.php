<?php

declare(strict_types=1);

namespace App\Modules\Essentia\Utils;

/**
 * Utility functions for Essentia processing
 */
class AudioUtils
{
    /**
     * Convert frequency to musical note
     */
    public static function frequencyToNote(float $frequency): string
    {
        if ($frequency <= 0) {
            return 'N/A';
        }

        $noteNames = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        $A4 = 440.0;
        
        $semitones = round(12 * log($frequency / $A4) / log(2));
        $octave = 4 + intval($semitones / 12);
        $noteIndex = ($semitones % 12 + 12) % 12;
        
        return $noteNames[$noteIndex] . $octave;
    }

    /**
     * Convert BPM to beat period in seconds
     */
    public static function bpmToPeriod(float $bpm): float
    {
        return 60.0 / $bpm;
    }

    /**
     * Convert beat period to BPM
     */
    public static function periodToBpm(float $period): float
    {
        return 60.0 / $period;
    }

    /**
     * Apply windowing function to audio data
     */
    public static function applyWindow(array $data, string $windowType = 'hann'): array
    {
        $length = count($data);
        $window = self::generateWindow($length, $windowType);
        
        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = $data[$i] * $window[$i];
        }
        
        return $result;
    }

    /**
     * Generate window function
     */
    public static function generateWindow(int $length, string $type = 'hann'): array
    {
        $window = [];
        
        switch ($type) {
            case 'hann':
            case 'hanning':
                for ($i = 0; $i < $length; $i++) {
                    $window[] = 0.5 * (1 - cos(2 * M_PI * $i / ($length - 1)));
                }
                break;
                
            case 'hamming':
                for ($i = 0; $i < $length; $i++) {
                    $window[] = 0.54 - 0.46 * cos(2 * M_PI * $i / ($length - 1));
                }
                break;
                
            case 'blackman':
                for ($i = 0; $i < $length; $i++) {
                    $window[] = 0.42 - 0.5 * cos(2 * M_PI * $i / ($length - 1)) + 0.08 * cos(4 * M_PI * $i / ($length - 1));
                }
                break;
                
            default:
                // Rectangular window
                $window = array_fill(0, $length, 1.0);
        }
        
        return $window;
    }

    /**
     * Calculate RMS of audio data
     */
    public static function calculateRMS(array $data): float
    {
        if (empty($data)) {
            return 0.0;
        }

        $sum = array_sum(array_map(fn($sample) => $sample * $sample, $data));
        return sqrt($sum / count($data));
    }

    /**
     * Find peaks in audio data
     */
    public static function findPeaks(array $data, float $threshold = 0.1): array
    {
        $peaks = [];
        $length = count($data);
        
        for ($i = 1; $i < $length - 1; $i++) {
            if ($data[$i] > $data[$i - 1] && 
                $data[$i] > $data[$i + 1] && 
                $data[$i] > $threshold) {
                $peaks[] = [
                    'index' => $i,
                    'value' => $data[$i]
                ];
            }
        }
        
        return $peaks;
    }
}