<?php

declare(strict_types=1);

namespace App\Modules\Essentia;

use App\Modules\Essentia\Exceptions\EssentiaException;
use App\Modules\Essentia\Algorithms\Sfx\AfterMaxToBeforeMaxEnergyRatio;
use App\Modules\Essentia\Algorithms\Filters\AllPass;
use App\Modules\Essentia\Algorithms\Io\Audio2Midi;
use App\Modules\Essentia\Algorithms\Io\Audio2Pitch;
use App\Modules\Essentia\Algorithms\Io\AudioLoader;
use App\Modules\Essentia\Algorithms\Io\AudioOnsetsMarker;
use App\Modules\Essentia\Algorithms\Io\AudioWriter;
use App\Modules\Essentia\Algorithms\Stats\AutoCorrelation;
use App\Modules\Essentia\Algorithms\Spectral\BFCC;
use App\Modules\Essentia\Algorithms\Filters\BPF;
use App\Modules\Essentia\Algorithms\Filters\BandPass;
use App\Modules\Essentia\Algorithms\Filters\BandReject;
use App\Modules\Essentia\Algorithms\Spectral\BarkBands;
use App\Modules\Essentia\Algorithms\Streaming\BarkExtractor;
use App\Modules\Essentia\Algorithms\Rhythm\BeatTrackerDegara;
use App\Modules\Essentia\Algorithms\Rhythm\BeatTrackerMultiFeature;
use App\Modules\Essentia\Algorithms\Rhythm\Beatogram;
use App\Modules\Essentia\Algorithms\Rhythm\BeatsLoudness;
use App\Modules\Essentia\Algorithms\Standard\BinaryOperator;
use App\Modules\Essentia\Algorithms\Standard\BinaryOperatorStream;
use App\Modules\Essentia\Algorithms\Rhythm\BpmHistogram;
use App\Modules\Essentia\Algorithms\Rhythm\BpmHistogramDescriptors;
use App\Modules\Essentia\Algorithms\Rhythm\BpmRubato;
use App\Modules\Essentia\Algorithms\Complex\CartesianToPolar;
use App\Modules\Essentia\Algorithms\Stats\CentralMoments;
use App\Modules\Essentia\Algorithms\Spectral\Centroid;
use App\Modules\Essentia\Algorithms\Tonal\ChordsDescriptors;
use App\Modules\Essentia\Algorithms\Tonal\ChordsDetection;
use App\Modules\Essentia\Algorithms\Rhythm\ChordsDetectionBeats;
use App\Modules\Essentia\Algorithms\HighLevel\ChromaCrossSimilarity;
use App\Modules\Essentia\Algorithms\Tonal\Chromagram;
use App\Modules\Essentia\Algorithms\Tonal\Chromaprinter;
use App\Modules\Essentia\Algorithms\AudioProblems\ClickDetector;
use App\Modules\Essentia\Algorithms\Standard\Clipper;
use App\Modules\Essentia\Algorithms\Streaming\CompositeBase;
use App\Modules\Essentia\Algorithms\Spectral\ConstantQ;
use App\Modules\Essentia\Algorithms\HighLevel\CoverSongSimilarity;
use App\Modules\Essentia\Algorithms\Sfx\Crest;
use App\Modules\Essentia\Algorithms\Stats\CrossCorrelation;
use App\Modules\Essentia\Algorithms\HighLevel\CrossSimilarityMatrix;
use App\Modules\Essentia\Algorithms\Standard\CubicSpline;
use App\Modules\Essentia\Algorithms\Filters\DCRemoval;
use App\Modules\Essentia\Algorithms\Standard\DCT;
use App\Modules\Essentia\Algorithms\HighLevel\Danceability;
use App\Modules\Essentia\Algorithms\Sfx\Decrease;
use App\Modules\Essentia\Algorithms\Sfx\Derivative;
use App\Modules\Essentia\Algorithms\Sfx\DerivativeSFX;
use App\Modules\Essentia\Algorithms\AudioProblems\DiscontinuityDetector;
use App\Modules\Essentia\Algorithms\Tonal\Dissonance;
use App\Modules\Essentia\Algorithms\Stats\DistributionShape;
use App\Modules\Essentia\Algorithms\Standard\Duration;
use App\Modules\Essentia\Algorithms\Temporal\DynamicComplexity;
use App\Modules\Essentia\Algorithms\Spectral\ERBBands;
use App\Modules\Essentia\Algorithms\Io\EasyLoader;
use App\Modules\Essentia\Algorithms\Standard\EffectiveDuration;
use App\Modules\Essentia\Algorithms\Temporal\Energy;
use App\Modules\Essentia\Algorithms\Temporal\EnergyBand;
use App\Modules\Essentia\Algorithms\Temporal\EnergyBandRatio;
use App\Modules\Essentia\Algorithms\Stats\Entropy;
use App\Modules\Essentia\Algorithms\Temporal\Envelope;
use App\Modules\Essentia\Algorithms\Io\EqloudLoader;
use App\Modules\Essentia\Algorithms\Filters\EqualLoudness;
use App\Modules\Essentia\Algorithms\Standard\Extractor;
use App\Modules\Essentia\Algorithms\Spectral\FFT;
use App\Modules\Essentia\Algorithms\Complex\FFTC;
use App\Modules\Essentia\Algorithms\AudioProblems\FadeDetection;
use App\Modules\Essentia\Algorithms\AudioProblems\FalseStereoDetector;
use App\Modules\Essentia\Algorithms\Streaming\FileOutput;
use App\Modules\Essentia\Algorithms\Sfx\Flatness;
use App\Modules\Essentia\Algorithms\Sfx\FlatnessDB;
use App\Modules\Essentia\Algorithms\Sfx\FlatnessSFX;
use App\Modules\Essentia\Algorithms\Spectral\Flux;
use App\Modules\Essentia\Algorithms\Standard\FrameBuffer;
use App\Modules\Essentia\Algorithms\Standard\FrameCutter;
use App\Modules\Essentia\Algorithms\Standard\FrameGenerator;
use App\Modules\Essentia\Algorithms\Standard\FrameToReal;
use App\Modules\Essentia\Algorithms\Extractor\FreesoundExtractor;
use App\Modules\Essentia\Algorithms\Spectral\FrequencyBands;
use App\Modules\Essentia\Algorithms\Spectral\GFCC;
use App\Modules\Essentia\Algorithms\HighLevel\GaiaTransform;
use App\Modules\Essentia\Algorithms\AudioProblems\GapsDetector;
use App\Modules\Essentia\Algorithms\Stats\GeometricMean;
use App\Modules\Essentia\Algorithms\Spectral\HFC;
use App\Modules\Essentia\Algorithms\Tonal\HPCP;
use App\Modules\Essentia\Algorithms\Rhythm\HarmonicBpm;
use App\Modules\Essentia\Algorithms\Tonal\HarmonicMask;
use App\Modules\Essentia\Algorithms\Synthesis\HarmonicModelAnal;
use App\Modules\Essentia\Algorithms\Tonal\HarmonicPeaks;
use App\Modules\Essentia\Algorithms\Filters\HighPass;
use App\Modules\Essentia\Algorithms\HighLevel\HighResolutionFeatures;
use App\Modules\Essentia\Algorithms\Stats\Histogram;
use App\Modules\Essentia\Algorithms\Synthesis\HprModelAnal;
use App\Modules\Essentia\Algorithms\Synthesis\HpsModelAnal;
use App\Modules\Essentia\Algorithms\AudioProblems\HumDetector;
use App\Modules\Essentia\Algorithms\Standard\IDCT;
use App\Modules\Essentia\Algorithms\Spectral\IFFT;
use App\Modules\Essentia\Algorithms\Complex\IFFTC;
use App\Modules\Essentia\Algorithms\Filters\IIR;
use App\Modules\Essentia\Algorithms\Tonal\Inharmonicity;
use App\Modules\Essentia\Algorithms\Temporal\InstantPower;
use App\Modules\Essentia\Algorithms\Temporal\Intensity;
use App\Modules\Essentia\Algorithms\Tonal\Key;
use App\Modules\Essentia\Algorithms\Extractor\KeyExtractor;
use App\Modules\Essentia\Algorithms\Synthesis\LPC;
use App\Modules\Essentia\Algorithms\Temporal\Larm;
use App\Modules\Essentia\Algorithms\Temporal\Leq;
use App\Modules\Essentia\Algorithms\Extractor\LevelExtractor;
use App\Modules\Essentia\Algorithms\Sfx\LogAttackTime;
use App\Modules\Essentia\Algorithms\Spectral\LogSpectrum;
use App\Modules\Essentia\Algorithms\Rhythm\LoopBpmConfidence;
use App\Modules\Essentia\Algorithms\Rhythm\LoopBpmEstimator;
use App\Modules\Essentia\Algorithms\Temporal\Loudness;
use App\Modules\Essentia\Algorithms\Temporal\LoudnessEBUR128;
use App\Modules\Essentia\Algorithms\Streaming\LoudnessEBUR128Filter;
use App\Modules\Essentia\Algorithms\Temporal\LoudnessVickers;
use App\Modules\Essentia\Algorithms\Extractor\LowLevelSpectralEqloudExtractor;
use App\Modules\Essentia\Algorithms\Extractor\LowLevelSpectralExtractor;
use App\Modules\Essentia\Algorithms\Filters\LowPass;
use App\Modules\Essentia\Algorithms\Spectral\MFCC;
use App\Modules\Essentia\Algorithms\Complex\Magnitude;
use App\Modules\Essentia\Algorithms\Filters\MaxFilter;
use App\Modules\Essentia\Algorithms\Standard\MaxMagFreq;
use App\Modules\Essentia\Algorithms\Sfx\MaxToTotal;
use App\Modules\Essentia\Algorithms\Stats\Mean;
use App\Modules\Essentia\Algorithms\Stats\Median;
use App\Modules\Essentia\Algorithms\Filters\MedianFilter;
use App\Modules\Essentia\Algorithms\Spectral\MelBands;
use App\Modules\Essentia\Algorithms\Io\MetadataReader;
use App\Modules\Essentia\Algorithms\HighLevel\Meter;
use App\Modules\Essentia\Algorithms\Stats\MinMax;
use App\Modules\Essentia\Algorithms\Sfx\MinToTotal;
use App\Modules\Essentia\Algorithms\Io\MonoLoader;
use App\Modules\Essentia\Algorithms\Io\MonoMixer;
use App\Modules\Essentia\Algorithms\Io\MonoWriter;
use App\Modules\Essentia\Algorithms\Filters\MovingAverage;
use App\Modules\Essentia\Algorithms\Spectral\MultiPitchKlapuri;
use App\Modules\Essentia\Algorithms\Spectral\MultiPitchMelodia;
use App\Modules\Essentia\Algorithms\Standard\Multiplexer;
use App\Modules\Essentia\Algorithms\Extractor\MusicExtractor;
use App\Modules\Essentia\Algorithms\Extractor\MusicExtractorSVM;
use App\Modules\Essentia\Algorithms\Tonal\NNLSChroma;
use App\Modules\Essentia\Algorithms\Spectral\NSGConstantQ;
use App\Modules\Essentia\Algorithms\Streaming\NSGConstantQStreaming;
use App\Modules\Essentia\Algorithms\Spectral\NSGIConstantQ;
use App\Modules\Essentia\Algorithms\AudioProblems\NoiseAdder;
use App\Modules\Essentia\Algorithms\AudioProblems\NoiseBurstDetector;
use App\Modules\Essentia\Algorithms\Rhythm\NoveltyCurve;
use App\Modules\Essentia\Algorithms\Rhythm\NoveltyCurveFixedBpmEstimator;
use App\Modules\Essentia\Algorithms\Sfx\OddToEvenHarmonicEnergyRatio;
use App\Modules\Essentia\Algorithms\Rhythm\OnsetDetection;
use App\Modules\Essentia\Algorithms\Rhythm\OnsetDetectionGlobal;
use App\Modules\Essentia\Algorithms\Rhythm\OnsetRate;
use App\Modules\Essentia\Algorithms\Rhythm\Onsets;
use App\Modules\Essentia\Algorithms\Standard\OverlapAdd;
use App\Modules\Essentia\Algorithms\MachineLearning\PCA;
use App\Modules\Essentia\Algorithms\Standard\Panning;
use App\Modules\Essentia\Algorithms\AudioProblems\PeakDetection;
use App\Modules\Essentia\Algorithms\Rhythm\PercivalBpmEstimator;
use App\Modules\Essentia\Algorithms\Tonal\PercivalEnhanceHarmonics;
use App\Modules\Essentia\Algorithms\Standard\PercivalEvaluatePulseTrains;
use App\Modules\Essentia\Algorithms\Tonal\Pitch2Midi;
use App\Modules\Essentia\Algorithms\Spectral\PitchContourSegmentation;
use App\Modules\Essentia\Algorithms\Spectral\PitchContours;
use App\Modules\Essentia\Algorithms\Spectral\PitchContoursMelody;
use App\Modules\Essentia\Algorithms\Io\PitchContoursMonoMelody;
use App\Modules\Essentia\Algorithms\Spectral\PitchContoursMultiMelody;
use App\Modules\Essentia\Algorithms\Tonal\PitchFilter;
use App\Modules\Essentia\Algorithms\Spectral\PitchMelodia;
use App\Modules\Essentia\Algorithms\Tonal\PitchSalience;
use App\Modules\Essentia\Algorithms\Tonal\PitchSalienceFunction;
use App\Modules\Essentia\Algorithms\Tonal\PitchSalienceFunctionPeaks;
use App\Modules\Essentia\Algorithms\Tonal\PitchYin;
use App\Modules\Essentia\Algorithms\Spectral\PitchYinFFT;
use App\Modules\Essentia\Algorithms\Tonal\PitchYinProbabilistic;
use App\Modules\Essentia\Algorithms\Tonal\PitchYinProbabilities;
use App\Modules\Essentia\Algorithms\Tonal\PitchYinProbabilitiesHMM;
use App\Modules\Essentia\Algorithms\Complex\PolarToCartesian;
use App\Modules\Essentia\Algorithms\Stats\PoolAggregator;
use App\Modules\Essentia\Algorithms\Streaming\PoolToTensor;
use App\Modules\Essentia\Algorithms\Stats\PowerMean;
use App\Modules\Essentia\Algorithms\Spectral\PowerSpectrum;
use App\Modules\Essentia\Algorithms\Spectral\PredominantPitchMelodia;
use App\Modules\Essentia\Algorithms\Temporal\RMS;
use App\Modules\Essentia\Algorithms\Stats\RawMoments;
use App\Modules\Essentia\Algorithms\Streaming\RealAccumulator;
use App\Modules\Essentia\Algorithms\Temporal\ReplayGain;
use App\Modules\Essentia\Algorithms\Standard\Resample;
use App\Modules\Essentia\Algorithms\Spectral\ResampleFFT;
use App\Modules\Essentia\Algorithms\Rhythm\RhythmDescriptors;
use App\Modules\Essentia\Algorithms\Extractor\RhythmExtractor;
use App\Modules\Essentia\Algorithms\Extractor\RhythmExtractor2013;
use App\Modules\Essentia\Algorithms\Rhythm\RhythmTransform;
use App\Modules\Essentia\Algorithms\Spectral\RollOff;
use App\Modules\Essentia\Algorithms\MachineLearning\SBic;
use App\Modules\Essentia\Algorithms\Standard\SNR;
use App\Modules\Essentia\Algorithms\AudioProblems\SaturationDetector;
use App\Modules\Essentia\Algorithms\Standard\Scale;
use App\Modules\Essentia\Algorithms\AudioProblems\SilenceRate;
use App\Modules\Essentia\Algorithms\Synthesis\SineModelAnal;
use App\Modules\Essentia\Algorithms\Synthesis\SineModelSynth;
use App\Modules\Essentia\Algorithms\Synthesis\SineSubtraction;
use App\Modules\Essentia\Algorithms\Rhythm\SingleBeatLoudness;
use App\Modules\Essentia\Algorithms\Stats\SingleGaussian;
use App\Modules\Essentia\Algorithms\Standard\Slicer;
use App\Modules\Essentia\Algorithms\Spectral\SpectralCentroidTime;
use App\Modules\Essentia\Algorithms\Spectral\SpectralComplexity;
use App\Modules\Essentia\Algorithms\Spectral\SpectralContrast;
use App\Modules\Essentia\Algorithms\Spectral\SpectralPeaks;
use App\Modules\Essentia\Algorithms\Spectral\SpectralWhitening;
use App\Modules\Essentia\Algorithms\Spectral\Spectrum;
use App\Modules\Essentia\Algorithms\Spectral\SpectrumCQ;
use App\Modules\Essentia\Algorithms\Spectral\SpectrumToCent;
use App\Modules\Essentia\Algorithms\Standard\Spline;
use App\Modules\Essentia\Algorithms\Synthesis\SprModelAnal;
use App\Modules\Essentia\Algorithms\Synthesis\SprModelSynth;
use App\Modules\Essentia\Algorithms\Synthesis\SpsModelAnal;
use App\Modules\Essentia\Algorithms\Synthesis\SpsModelSynth;
use App\Modules\Essentia\Algorithms\AudioProblems\StartStopCut;
use App\Modules\Essentia\Algorithms\AudioProblems\StartStopSilence;
use App\Modules\Essentia\Algorithms\Standard\StereoDemuxer;
use App\Modules\Essentia\Algorithms\Standard\StereoMuxer;
use App\Modules\Essentia\Algorithms\Standard\StereoTrimmer;
use App\Modules\Essentia\Algorithms\Synthesis\StochasticModelAnal;
use App\Modules\Essentia\Algorithms\Synthesis\StochasticModelSynth;
use App\Modules\Essentia\Algorithms\Sfx\StrongDecay;
use App\Modules\Essentia\Algorithms\Sfx\StrongPeak;
use App\Modules\Essentia\Algorithms\Spectral\SuperFluxExtractor;
use App\Modules\Essentia\Algorithms\Rhythm\SuperFluxNovelty;
use App\Modules\Essentia\Algorithms\Spectral\SuperFluxPeaks;
use App\Modules\Essentia\Algorithms\Sfx\TCToTotal;
use App\Modules\Essentia\Algorithms\Rhythm\TempoScaleBands;
use App\Modules\Essentia\Algorithms\Rhythm\TempoTap;
use App\Modules\Essentia\Algorithms\Rhythm\TempoTapDegara;
use App\Modules\Essentia\Algorithms\Rhythm\TempoTapMaxAgreement;
use App\Modules\Essentia\Algorithms\Rhythm\TempoTapTicks;
use App\Modules\Essentia\Algorithms\MachineLearning\TensorNormalize;
use App\Modules\Essentia\Algorithms\Streaming\TensorToPool;
use App\Modules\Essentia\Algorithms\Streaming\TensorToVectorReal;
use App\Modules\Essentia\Algorithms\MachineLearning\TensorTranspose;
use App\Modules\Essentia\Algorithms\MachineLearning\TensorflowInputFSDSINet;
use App\Modules\Essentia\Algorithms\MachineLearning\TensorflowInputMusiCNN;
use App\Modules\Essentia\Algorithms\MachineLearning\TensorflowInputTempoCNN;
use App\Modules\Essentia\Algorithms\MachineLearning\TensorflowInputVGGish;
use App\Modules\Essentia\Algorithms\Extractor\TonalExtractor;
use App\Modules\Essentia\Algorithms\Tonal\TonicIndianArtMusic;
use App\Modules\Essentia\Algorithms\Spectral\TriangularBands;
use App\Modules\Essentia\Algorithms\Spectral\TriangularBarkBands;
use App\Modules\Essentia\Algorithms\Standard\Trimmer;
use App\Modules\Essentia\Algorithms\Tonal\Tristimulus;
use App\Modules\Essentia\Algorithms\AudioProblems\TruePeakDetector;
use App\Modules\Essentia\Algorithms\Tonal\TuningFrequency;
use App\Modules\Essentia\Algorithms\Extractor\TuningFrequencyExtractor;
use App\Modules\Essentia\Algorithms\Standard\UnaryOperator;
use App\Modules\Essentia\Algorithms\Standard\UnaryOperatorStream;
use App\Modules\Essentia\Algorithms\Stats\Variance;
use App\Modules\Essentia\Algorithms\Streaming\VectorInput;
use App\Modules\Essentia\Algorithms\Streaming\VectorRealAccumulator;
use App\Modules\Essentia\Algorithms\Streaming\VectorRealToTensor;
use App\Modules\Essentia\Algorithms\Tonal\Vibrato;
use App\Modules\Essentia\Algorithms\MachineLearning\Viterbi;
use App\Modules\Essentia\Algorithms\Stats\WarpedAutoCorrelation;
use App\Modules\Essentia\Algorithms\Spectral\Welch;
use App\Modules\Essentia\Algorithms\Standard\Windowing;
use App\Modules\Essentia\Algorithms\Io\YamlInput;
use App\Modules\Essentia\Algorithms\Io\YamlOutput;
use App\Modules\Essentia\Algorithms\Temporal\ZeroCrossingRate;


class AlgorithmFactory
{
    private const ALGORITHM_MAP = [
            'AfterMaxToBeforeMaxEnergyRatio' => AfterMaxToBeforeMaxEnergyRatio::class,
            'AllPass' => AllPass::class,
            'Audio2Midi' => Audio2Midi::class,
            'Audio2Pitch' => Audio2Pitch::class,
            'AudioLoader' => AudioLoader::class,
            'AudioOnsetsMarker' => AudioOnsetsMarker::class,
            'AudioWriter' => AudioWriter::class,
            'AutoCorrelation' => AutoCorrelation::class,
            'BFCC' => BFCC::class,
            'BPF' => BPF::class,
            'BandPass' => BandPass::class,
            'BandReject' => BandReject::class,
            'BarkBands' => BarkBands::class,
            'BarkExtractor' => BarkExtractor::class,
            'BeatTrackerDegara' => BeatTrackerDegara::class,
            'BeatTrackerMultiFeature' => BeatTrackerMultiFeature::class,
            'Beatogram' => Beatogram::class,
            'BeatsLoudness' => BeatsLoudness::class,
            'BinaryOperator' => BinaryOperator::class,
            'BinaryOperatorStream' => BinaryOperatorStream::class,
            'BpmHistogram' => BpmHistogram::class,
            'BpmHistogramDescriptors' => BpmHistogramDescriptors::class,
            'BpmRubato' => BpmRubato::class,
            'CartesianToPolar' => CartesianToPolar::class,
            'CentralMoments' => CentralMoments::class,
            'Centroid' => Centroid::class,
            'ChordsDescriptors' => ChordsDescriptors::class,
            'ChordsDetection' => ChordsDetection::class,
            'ChordsDetectionBeats' => ChordsDetectionBeats::class,
            'ChromaCrossSimilarity' => ChromaCrossSimilarity::class,
            'Chromagram' => Chromagram::class,
            'Chromaprinter' => Chromaprinter::class,
            'ClickDetector' => ClickDetector::class,
            'Clipper' => Clipper::class,
            'CompositeBase' => CompositeBase::class,
            'ConstantQ' => ConstantQ::class,
            'CoverSongSimilarity' => CoverSongSimilarity::class,
            'Crest' => Crest::class,
            'CrossCorrelation' => CrossCorrelation::class,
            'CrossSimilarityMatrix' => CrossSimilarityMatrix::class,
            'CubicSpline' => CubicSpline::class,
            'DCRemoval' => DCRemoval::class,
            'DCT' => DCT::class,
            'Danceability' => Danceability::class,
            'Decrease' => Decrease::class,
            'Derivative' => Derivative::class,
            'DerivativeSFX' => DerivativeSFX::class,
            'DiscontinuityDetector' => DiscontinuityDetector::class,
            'Dissonance' => Dissonance::class,
            'DistributionShape' => DistributionShape::class,
            'Duration' => Duration::class,
            'DynamicComplexity' => DynamicComplexity::class,
            'ERBBands' => ERBBands::class,
            'EasyLoader' => EasyLoader::class,
            'EffectiveDuration' => EffectiveDuration::class,
            'Energy' => Energy::class,
            'EnergyBand' => EnergyBand::class,
            'EnergyBandRatio' => EnergyBandRatio::class,
            'Entropy' => Entropy::class,
            'Envelope' => Envelope::class,
            'EqloudLoader' => EqloudLoader::class,
            'EqualLoudness' => EqualLoudness::class,
            'Extractor' => Extractor::class,
            'FFT' => FFT::class,
            'FFTC' => FFTC::class,
            'FadeDetection' => FadeDetection::class,
            'FalseStereoDetector' => FalseStereoDetector::class,
            'FileOutput' => FileOutput::class,
            'Flatness' => Flatness::class,
            'FlatnessDB' => FlatnessDB::class,
            'FlatnessSFX' => FlatnessSFX::class,
            'Flux' => Flux::class,
            'FrameBuffer' => FrameBuffer::class,
            'FrameCutter' => FrameCutter::class,
            'FrameGenerator' => FrameGenerator::class,
            'FrameToReal' => FrameToReal::class,
            'FreesoundExtractor' => FreesoundExtractor::class,
            'FrequencyBands' => FrequencyBands::class,
            'GFCC' => GFCC::class,
            'GaiaTransform' => GaiaTransform::class,
            'GapsDetector' => GapsDetector::class,
            'GeometricMean' => GeometricMean::class,
            'HFC' => HFC::class,
            'HPCP' => HPCP::class,
            'HarmonicBpm' => HarmonicBpm::class,
            'HarmonicMask' => HarmonicMask::class,
            'HarmonicModelAnal' => HarmonicModelAnal::class,
            'HarmonicPeaks' => HarmonicPeaks::class,
            'HighPass' => HighPass::class,
            'HighResolutionFeatures' => HighResolutionFeatures::class,
            'Histogram' => Histogram::class,
            'HprModelAnal' => HprModelAnal::class,
            'HpsModelAnal' => HpsModelAnal::class,
            'HumDetector' => HumDetector::class,
            'IDCT' => IDCT::class,
            'IFFT' => IFFT::class,
            'IFFTC' => IFFTC::class,
            'IIR' => IIR::class,
            'Inharmonicity' => Inharmonicity::class,
            'InstantPower' => InstantPower::class,
            'Intensity' => Intensity::class,
            'Key' => Key::class,
            'KeyExtractor' => KeyExtractor::class,
            'LPC' => LPC::class,
            'Larm' => Larm::class,
            'Leq' => Leq::class,
            'LevelExtractor' => LevelExtractor::class,
            'LogAttackTime' => LogAttackTime::class,
            'LogSpectrum' => LogSpectrum::class,
            'LoopBpmConfidence' => LoopBpmConfidence::class,
            'LoopBpmEstimator' => LoopBpmEstimator::class,
            'Loudness' => Loudness::class,
            'LoudnessEBUR128' => LoudnessEBUR128::class,
            'LoudnessEBUR128Filter' => LoudnessEBUR128Filter::class,
            'LoudnessVickers' => LoudnessVickers::class,
            'LowLevelSpectralEqloudExtractor' => LowLevelSpectralEqloudExtractor::class,
            'LowLevelSpectralExtractor' => LowLevelSpectralExtractor::class,
            'LowPass' => LowPass::class,
            'MFCC' => MFCC::class,
            'Magnitude' => Magnitude::class,
            'MaxFilter' => MaxFilter::class,
            'MaxMagFreq' => MaxMagFreq::class,
            'MaxToTotal' => MaxToTotal::class,
            'Mean' => Mean::class,
            'Median' => Median::class,
            'MedianFilter' => MedianFilter::class,
            'MelBands' => MelBands::class,
            'MetadataReader' => MetadataReader::class,
            'Meter' => Meter::class,
            'MinMax' => MinMax::class,
            'MinToTotal' => MinToTotal::class,
            'MonoLoader' => MonoLoader::class,
            'MonoMixer' => MonoMixer::class,
            'MonoWriter' => MonoWriter::class,
            'MovingAverage' => MovingAverage::class,
            'MultiPitchKlapuri' => MultiPitchKlapuri::class,
            'MultiPitchMelodia' => MultiPitchMelodia::class,
            'Multiplexer' => Multiplexer::class,
            'MusicExtractor' => MusicExtractor::class,
            'MusicExtractorSVM' => MusicExtractorSVM::class,
            'NNLSChroma' => NNLSChroma::class,
            'NSGConstantQ' => NSGConstantQ::class,
            'NSGConstantQStreaming' => NSGConstantQStreaming::class,
            'NSGIConstantQ' => NSGIConstantQ::class,
            'NoiseAdder' => NoiseAdder::class,
            'NoiseBurstDetector' => NoiseBurstDetector::class,
            'NoveltyCurve' => NoveltyCurve::class,
            'NoveltyCurveFixedBpmEstimator' => NoveltyCurveFixedBpmEstimator::class,
            'OddToEvenHarmonicEnergyRatio' => OddToEvenHarmonicEnergyRatio::class,
            'OnsetDetection' => OnsetDetection::class,
            'OnsetDetectionGlobal' => OnsetDetectionGlobal::class,
            'OnsetRate' => OnsetRate::class,
            'Onsets' => Onsets::class,
            'OverlapAdd' => OverlapAdd::class,
            'PCA' => PCA::class,
            'Panning' => Panning::class,
            'PeakDetection' => PeakDetection::class,
            'PercivalBpmEstimator' => PercivalBpmEstimator::class,
            'PercivalEnhanceHarmonics' => PercivalEnhanceHarmonics::class,
            'PercivalEvaluatePulseTrains' => PercivalEvaluatePulseTrains::class,
            'Pitch2Midi' => Pitch2Midi::class,
            'PitchContourSegmentation' => PitchContourSegmentation::class,
            'PitchContours' => PitchContours::class,
            'PitchContoursMelody' => PitchContoursMelody::class,
            'PitchContoursMonoMelody' => PitchContoursMonoMelody::class,
            'PitchContoursMultiMelody' => PitchContoursMultiMelody::class,
            'PitchFilter' => PitchFilter::class,
            'PitchMelodia' => PitchMelodia::class,
            'PitchSalience' => PitchSalience::class,
            'PitchSalienceFunction' => PitchSalienceFunction::class,
            'PitchSalienceFunctionPeaks' => PitchSalienceFunctionPeaks::class,
            'PitchYin' => PitchYin::class,
            'PitchYinFFT' => PitchYinFFT::class,
            'PitchYinProbabilistic' => PitchYinProbabilistic::class,
            'PitchYinProbabilities' => PitchYinProbabilities::class,
            'PitchYinProbabilitiesHMM' => PitchYinProbabilitiesHMM::class,
            'PolarToCartesian' => PolarToCartesian::class,
            'PoolAggregator' => PoolAggregator::class,
            'PoolToTensor' => PoolToTensor::class,
            'PowerMean' => PowerMean::class,
            'PowerSpectrum' => PowerSpectrum::class,
            'PredominantPitchMelodia' => PredominantPitchMelodia::class,
            'RMS' => RMS::class,
            'RawMoments' => RawMoments::class,
            'RealAccumulator' => RealAccumulator::class,
            'ReplayGain' => ReplayGain::class,
            'Resample' => Resample::class,
            'ResampleFFT' => ResampleFFT::class,
            'RhythmDescriptors' => RhythmDescriptors::class,
            'RhythmExtractor' => RhythmExtractor::class,
            'RhythmExtractor2013' => RhythmExtractor2013::class,
            'RhythmTransform' => RhythmTransform::class,
            'RollOff' => RollOff::class,
            'SBic' => SBic::class,
            'SNR' => SNR::class,
            'SaturationDetector' => SaturationDetector::class,
            'Scale' => Scale::class,
            'SilenceRate' => SilenceRate::class,
            'SineModelAnal' => SineModelAnal::class,
            'SineModelSynth' => SineModelSynth::class,
            'SineSubtraction' => SineSubtraction::class,
            'SingleBeatLoudness' => SingleBeatLoudness::class,
            'SingleGaussian' => SingleGaussian::class,
            'Slicer' => Slicer::class,
            'SpectralCentroidTime' => SpectralCentroidTime::class,
            'SpectralComplexity' => SpectralComplexity::class,
            'SpectralContrast' => SpectralContrast::class,
            'SpectralPeaks' => SpectralPeaks::class,
            'SpectralWhitening' => SpectralWhitening::class,
            'Spectrum' => Spectrum::class,
            'SpectrumCQ' => SpectrumCQ::class,
            'SpectrumToCent' => SpectrumToCent::class,
            'Spline' => Spline::class,
            'SprModelAnal' => SprModelAnal::class,
            'SprModelSynth' => SprModelSynth::class,
            'SpsModelAnal' => SpsModelAnal::class,
            'SpsModelSynth' => SpsModelSynth::class,
            'StartStopCut' => StartStopCut::class,
            'StartStopSilence' => StartStopSilence::class,
            'StereoDemuxer' => StereoDemuxer::class,
            'StereoMuxer' => StereoMuxer::class,
            'StereoTrimmer' => StereoTrimmer::class,
            'StochasticModelAnal' => StochasticModelAnal::class,
            'StochasticModelSynth' => StochasticModelSynth::class,
            'StrongDecay' => StrongDecay::class,
            'StrongPeak' => StrongPeak::class,
            'SuperFluxExtractor' => SuperFluxExtractor::class,
            'SuperFluxNovelty' => SuperFluxNovelty::class,
            'SuperFluxPeaks' => SuperFluxPeaks::class,
            'TCToTotal' => TCToTotal::class,
            'TempoScaleBands' => TempoScaleBands::class,
            'TempoTap' => TempoTap::class,
            'TempoTapDegara' => TempoTapDegara::class,
            'TempoTapMaxAgreement' => TempoTapMaxAgreement::class,
            'TempoTapTicks' => TempoTapTicks::class,
            'TensorNormalize' => TensorNormalize::class,
            'TensorToPool' => TensorToPool::class,
            'TensorToVectorReal' => TensorToVectorReal::class,
            'TensorTranspose' => TensorTranspose::class,
            'TensorflowInputFSDSINet' => TensorflowInputFSDSINet::class,
            'TensorflowInputMusiCNN' => TensorflowInputMusiCNN::class,
            'TensorflowInputTempoCNN' => TensorflowInputTempoCNN::class,
            'TensorflowInputVGGish' => TensorflowInputVGGish::class,
            'TonalExtractor' => TonalExtractor::class,
            'TonicIndianArtMusic' => TonicIndianArtMusic::class,
            'TriangularBands' => TriangularBands::class,
            'TriangularBarkBands' => TriangularBarkBands::class,
            'Trimmer' => Trimmer::class,
            'Tristimulus' => Tristimulus::class,
            'TruePeakDetector' => TruePeakDetector::class,
            'TuningFrequency' => TuningFrequency::class,
            'TuningFrequencyExtractor' => TuningFrequencyExtractor::class,
            'UnaryOperator' => UnaryOperator::class,
            'UnaryOperatorStream' => UnaryOperatorStream::class,
            'Variance' => Variance::class,
            'VectorInput' => VectorInput::class,
            'VectorRealAccumulator' => VectorRealAccumulator::class,
            'VectorRealToTensor' => VectorRealToTensor::class,
            'Vibrato' => Vibrato::class,
            'Viterbi' => Viterbi::class,
            'WarpedAutoCorrelation' => WarpedAutoCorrelation::class,
            'Welch' => Welch::class,
            'Windowing' => Windowing::class,
            'YamlInput' => YamlInput::class,
            'YamlOutput' => YamlOutput::class,
            'ZeroCrossingRate' => ZeroCrossingRate::class,
    ];

    public static function create(string $algorithmName, array $parameters = []): object
    {
        if (!isset(self::ALGORITHM_MAP[$algorithmName])) {
            throw new EssentiaException("Unknown algorithm: {$algorithmName}");
        }

        $algorithmClass = self::ALGORITHM_MAP[$algorithmName];
        
        return new $algorithmClass($parameters);
    }

    public static function getAvailableAlgorithms(): array
    {
        return array_keys(self::ALGORITHM_MAP);
    }

    public static function getAlgorithmsByCategory(): array
    {
        $byCategory = [];
        
        foreach (self::ALGORITHM_MAP as $name => $class) {
            $instance = new $class();
            $category = $instance->getCategory();
            $byCategory[$category][] = $name;
        }
        
        return $byCategory;
    }

    public static function algorithmExists(string $algorithmName): bool
    {
        return isset(self::ALGORITHM_MAP[$algorithmName]);
    }
}