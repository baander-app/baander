import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useAppDispatch, useAppSelector } from '@/store/hooks';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { globalAudioProcessor } from '@/services/global-audio-processor-service.ts';
import {
  setLufs,
  setNormalizationGain,
  setVolumeNormalization,
} from '@/store/music/music-player-slice';
import styles from './equalizer.module.scss';

interface EqualizerProps {
  className?: string;
}

interface EqualizerBand {
  frequency: number;
  label: string;
  gain: number;
  q: number;
}

interface AnalysisData {
  frequencyData: Uint8Array;
  timeDomainData: Uint8Array;
  leftChannel: number;
  rightChannel: number;
  lufs: number;
  peakFrequency: number;
  rms: number;
}

const EQ_BANDS: EqualizerBand[] = [
  { frequency: 31.5, label: '31.5', gain: 0, q: 0.7 },
  { frequency: 63, label: '63', gain: 0, q: 0.7 },
  { frequency: 125, label: '125', gain: 0, q: 0.7 },
  { frequency: 250, label: '250', gain: 0, q: 0.7 },
  { frequency: 500, label: '500', gain: 0, q: 0.7 },
  { frequency: 1000, label: '1K', gain: 0, q: 0.7 },
  { frequency: 2000, label: '2K', gain: 0, q: 0.7 },
  { frequency: 4000, label: '4K', gain: 0, q: 0.7 },
  { frequency: 8000, label: '8K', gain: 0, q: 0.7 },
  { frequency: 16000, label: '16K', gain: 0, q: 0.7 }
];

const EQ_PRESETS = {
  'FLAT': [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
  'ROCK': [4, 3, -1, -2, -1, 2, 4, 5, 5, 5],
  'POP': [2, 3, 4, 3, 0, -1, -2, -1, 2, 3],
  'JAZZ': [3, 2, 1, 2, 3, 3, 2, 1, 2, 3],
  'CLASSICAL': [4, 3, 2, 1, 0, 0, 2, 3, 4, 5],
  'ELECTRONIC': [5, 4, 2, 0, -1, 2, 3, 4, 5, 6],
  'HIP-HOP': [5, 4, 2, 3, -1, -1, 2, 3, 4, 5],
  'VOCAL': [2, 1, -1, 2, 4, 4, 3, 2, 1, -1],
  'ACOUSTIC': [3, 2, 1, 2, 3, 2, 3, 4, 3, 2],
  'BASS_BOOST': [7, 5, 3, 2, 0, 0, 0, 0, 0, 0],
  'TREBLE_BOOST': [0, 0, 0, 0, 0, 2, 4, 6, 8, 9],
  'CUSTOM': [0, 0, 0, 0, 0, 0, 0, 0, 0, 0]
};

export const Equalizer: React.FC<EqualizerProps> = ({
                                                                                          className
                                                                                        }) => {
  const dispatch = useAppDispatch();
  const animationFrame = useRef<number | undefined>(undefined);

  const {
    volume,
    setCurrentVolume,
    isMuted,
    toggleMuteUnmute,
    isPlaying
  } = useAudioPlayer();

  const { volume: volumeState } = useAppSelector(state => state.musicPlayer);
  const normalization = volumeState.normalization;

  // Debug state
  const [debugInfo, setDebugInfo] = useState({
    processorExists: false,
    processorActive: false,
    connectionStatus: 'unknown',
    lastUpdateTime: 0,
    mode: 'unknown'
  });

  const [eqState, setEqState] = useState({
    isEnabled: true,
    currentPreset: 'FLAT',
    bands: [...EQ_BANDS],
    displayMode: 'SPECTRUM' as 'SPECTRUM' | 'METERS' | 'PHASE',
    compressionEnabled: true,
    spatialEnhancement: false,
    // Simplified manual controls
    masterGain: 0, // dB
    lufsTarget: -16, // LUFS
  });

  const [analysisData, setAnalysisData] = useState<AnalysisData>(() => ({
    frequencyData: new Uint8Array(1024),
    timeDomainData: new Uint8Array(2048),
    leftChannel: 0,
    rightChannel: 0,
    lufs: -30,
    peakFrequency: 0,
    rms: 0
  }));

  useEffect(() => {
    const audioProcessor = globalAudioProcessor.getProcessor();

    console.log('Setting up analysis effect...', {
      hasProcessor: !!audioProcessor,
      isPlaying,
      eqEnabled: eqState.isEnabled
    });

    if (audioProcessor && isPlaying) {
      let frameCount = 0;

      const updateAnalysis = () => {
        frameCount++;

        try {
          const isActive = audioProcessor.isActive;
          const data = audioProcessor.getAnalysisData();

          setDebugInfo(prev => ({
            ...prev,
            processorExists: true,
            processorActive: isActive,
            lastUpdateTime: Date.now(),
            connectionStatus: isActive ? 'active' : 'inactive',
            mode: (audioProcessor as any).passiveMode ? 'passive' : 'direct'
          }));

          if (data) {
            // Log some debug info every 60 frames (about once per second at 60fps)
            if (frameCount % 60 === 0) {
              console.log('Analysis data:', {
                leftChannel: data.leftChannel,
                rightChannel: data.rightChannel,
                lufs: data.lufs,
                frequencyDataLength: data.frequencyData.length,
                timeDomainDataLength: data.timeDomainData.length,
                peakFrequency: data.peakFrequency,
                mode: debugInfo.mode
              });
            }

            setAnalysisData(prevData => ({
              ...data,
              frequencyData: data.frequencyData.length > 0 ? data.frequencyData : prevData.frequencyData,
              timeDomainData: data.timeDomainData.length > 0 ? data.timeDomainData : prevData.timeDomainData,
            }));

            dispatch(setLufs(data.lufs));

            if (normalization.enabled && data.lufs !== 0 && !isNaN(data.lufs)) {
              const targetLufs = eqState.lufsTarget;
              const currentLufs = data.lufs;
              const gainDifference = targetLufs - currentLufs;
              const maxGainAdjustment = 6
              const limitedGain = Math.max(-maxGainAdjustment, Math.min(maxGainAdjustment, gainDifference));

              // Only update every 10 frames to prevent rapid fluctuations
              if (frameCount % 10 === 0) {
                const previousGain = normalization.currentGain || 0;
                const smoothingFactor = 0.1;
                const smoothedGain = previousGain + (limitedGain - previousGain) * smoothingFactor;

                dispatch(setNormalizationGain(smoothedGain));
              }
            } else if (!normalization.enabled) {
              dispatch(setNormalizationGain(0));
            }

            audioProcessor.setMasterGain(eqState.masterGain);
          }
        } catch (error) {
          console.error('Error in analysis update:', error);
        }

        if (isPlaying) {
          animationFrame.current = requestAnimationFrame(updateAnalysis);
        }
      };

      updateAnalysis();
    } else {
      setDebugInfo(prev => ({
        ...prev,
        processorExists: !!audioProcessor,
        processorActive: false,
        connectionStatus: !audioProcessor ? 'no processor' : !isPlaying ? 'not playing' : 'ready'
      }));
    }

    return () => {
      if (animationFrame.current) {
        cancelAnimationFrame(animationFrame.current);
      }
    };
  }, [dispatch, normalization.enabled, isPlaying, eqState.lufsTarget, eqState.masterGain, normalization.currentGain]);

  useEffect(() => {
    const audioProcessor = globalAudioProcessor.getProcessor();

    if (audioProcessor) {
      audioProcessor.setEnabled();
      audioProcessor.updateEQBands(eqState.bands.map(band => band.gain));
      audioProcessor.setVolume(volume / 100);
      audioProcessor.setMuted(isMuted);
      audioProcessor.setCompression(eqState.compressionEnabled);
      audioProcessor.setSpatialEnhancement(eqState.spatialEnhancement);

      audioProcessor.setMasterGain(eqState.masterGain);
    }
  }, [eqState, volume, isMuted]);

  const handlePresetChange = useCallback((presetName: keyof typeof EQ_PRESETS) => {
    const presetValues = EQ_PRESETS[presetName];
    setEqState(prev => ({
      ...prev,
      currentPreset: presetName,
      bands: prev.bands.map((band, index) => ({
        ...band,
        gain: presetValues[index] || 0
      }))
    }));
  }, []);

  const handleBandChange = useCallback((bandIndex: number, gain: number) => {
    setEqState(prev => ({
      ...prev,
      currentPreset: 'CUSTOM',
      bands: prev.bands.map((band, index) =>
        index === bandIndex ? { ...band, gain } : band
      )
    }));
  }, []);

  const handleVolumeChange = useCallback((value: number) => {
    setCurrentVolume(value);
  }, [setCurrentVolume]);

  const handleMuteToggle = useCallback(() => {
    toggleMuteUnmute();
  }, [toggleMuteUnmute]);

  const handleNormalizationToggle = useCallback(() => {
    const newEnabled = !normalization.enabled;
    dispatch(setVolumeNormalization(newEnabled));

    if (!newEnabled) {
      dispatch(setNormalizationGain(0));
    }

    console.log('Normalization toggled:', newEnabled);
  }, [dispatch, normalization.enabled]);

  const handleMasterGainChange = useCallback((value: number) => {
    setEqState(prev => ({
      ...prev,
      masterGain: value
    }));
  }, []);

  const handleLufsTargetChange = useCallback((value: number) => {
    setEqState(prev => ({
      ...prev,
      lufsTarget: value
    }));

    if (normalization.enabled) {
      dispatch(setNormalizationGain(0));
    }
  }, [dispatch, normalization.enabled]);

  const renderVFDDisplay = () => (
    <div className={styles.vfdDisplay}>
      <div className={styles.vfdHeader}>
        <div className={styles.statusIndicators}>
          <span className={`${styles.indicator} ${styles.active}`}>
            PWR
          </span>
          <span className={`${styles.indicator} ${isMuted ? styles.active : ''}`}>
            MUTE
          </span>
          <span className={`${styles.indicator} ${normalization.enabled ? styles.active : ''}`}>
            NORM
          </span>
          <span className={`${styles.indicator} ${debugInfo.processorActive ? styles.active : ''}`}>
            PROC
          </span>
        </div>
        <div className={styles.presetDisplay}>{eqState.currentPreset}</div>
      </div>

      <div className={styles.vfdContent}>
        {/* Spectrum Display */}
        <div
          className={`${styles.displayContainer} ${eqState.displayMode === 'SPECTRUM' ? styles.active : styles.hidden}`}
        >
          <div className={styles.spectrumDisplay}>
            {Array.from({ length: 64 }, (_, i) => {
              const dataIndex = Math.floor((i / 64) * analysisData.frequencyData.length);
              const height = analysisData.frequencyData[dataIndex] / 255 * 100;
              return (
                <div
                  key={i}
                  className={styles.spectrumBar}
                  style={{
                    height: `${Math.max(2, height)}%`,
                    backgroundColor: height > 80 ? '#ff4444' : height > 60 ? '#ffaa00' : '#00ff00'
                  }}
                />
              );
            })}
          </div>
        </div>

        {/* Level Meters Display */}
        <div
          className={`${styles.displayContainer} ${eqState.displayMode === 'METERS' ? styles.active : styles.hidden}`}
        >
          <div className={styles.levelMeters}>
            <div className={styles.channelMeter}>
              <span className={styles.channelLabel}>L</span>
              <div className={styles.meterBar}>
                <div
                  className={styles.meterFill}
                  style={{
                    width: `${Math.min(100, analysisData.leftChannel)}%`,
                    backgroundColor: analysisData.leftChannel > 80 ? '#ff4444' :
                                     analysisData.leftChannel > 60 ? '#ffaa00' : '#00ff00'
                  }}
                />
              </div>
              <span className={styles.levelValue}>
                {Math.round(analysisData.leftChannel)}
              </span>
            </div>
            <div className={styles.channelMeter}>
              <span className={styles.channelLabel}>R</span>
              <div className={styles.meterBar}>
                <div
                  className={styles.meterFill}
                  style={{
                    width: `${Math.min(100, analysisData.rightChannel)}%`,
                    backgroundColor: analysisData.rightChannel > 80 ? '#ff4444' :
                                     analysisData.rightChannel > 60 ? '#ffaa00' : '#00ff00'
                  }}
                />
              </div>
              <span className={styles.levelValue}>
                {Math.round(analysisData.rightChannel)}
              </span>
            </div>

            <div className={styles.frequencyDisplay}>
              <span className={styles.freqLabel}>PEAK:</span>
              <span className={styles.freqValue}>
                {analysisData.peakFrequency > 1000
                 ? `${(analysisData.peakFrequency / 1000).toFixed(1)}K`
                 : `${Math.round(analysisData.peakFrequency)}`}Hz
              </span>
            </div>

            {/* Debug info */}
            <div className={styles.debugInfo} style={{ fontSize: '10px', color: '#666' }}>
              <div>Mode: {debugInfo.mode}</div>
              <div>Status: {debugInfo.connectionStatus}</div>
              <div>Active: {debugInfo.processorActive ? 'Y' : 'N'}</div>
            </div>
          </div>
        </div>

        {/* Phase Display */}
        <div
          className={`${styles.displayContainer} ${eqState.displayMode === 'PHASE' ? styles.active : styles.hidden}`}
        >
          <div className={styles.phaseDisplay}>
            <div className={styles.phaseScope}>
              <svg width="100%" height="100%" viewBox="0 0 200 100">
                <defs>
                  <linearGradient id="phaseGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%" stopColor="#00ff00" />
                    <stop offset="50%" stopColor="#ffff00" />
                    <stop offset="100%" stopColor="#ff0000" />
                  </linearGradient>
                </defs>
                <path
                  d={`M 0,50 ${Array.from({ length: 50 }, (_, i) => {
                    const x = (i / 49) * 200;
                    const phaseCorrelation = Math.sin((analysisData.peakFrequency / 1000) * i * 0.1) * 30;
                    const y = 50 + phaseCorrelation * (analysisData.rms || 0.1);
                    return `L ${x},${Math.max(10, Math.min(90, y))}`;
                  }).join(' ')}`}
                  stroke="url(#phaseGradient)"
                  strokeWidth="2"
                  fill="none"
                />
              </svg>
            </div>
            <div className={styles.correlationMeter}>
              <span className={styles.correlationLabel}>CORR:</span>
              <span className={styles.correlationValue}>
                {((analysisData.leftChannel + analysisData.rightChannel) / 200).toFixed(2)}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div className={styles.vfdFooter}>
        <div className={styles.lufsDisplay}>
          <span className={styles.lufsLabel}>LUFS:</span>
          <span className={styles.lufsValue}>
            {analysisData.lufs.toFixed(1)}
          </span>
        </div>
        <div className={styles.volumeDisplay}>
          <span className={styles.volumeLabel}>VOL:</span>
          <span className={styles.volumeValue}>{volume}</span>
        </div>
        <div className={styles.gainDisplay}>
          <span className={styles.gainLabel}>GAIN:</span>
          <span className={styles.gainValue}>
            {eqState.masterGain.toFixed(1)}dB
          </span>
        </div>
      </div>
    </div>
  );

  const renderEqualizerBand = (band: EqualizerBand, index: number) => (
    <div key={band.frequency} className={styles.eqBand}>
      <div className={styles.frequencyLabel}>{band.label}Hz</div>
      <div className={styles.gainSlider}>
        <input
          type="range"
          min="-12"
          max="12"
          step="0.5"
          value={band.gain}
          onChange={(e) => handleBandChange(index, parseFloat(e.target.value))}
          className={styles.slider}
        />
        <div className={styles.gainValue}>
          {band.gain >= 0 ? '+' : ''}{band.gain.toFixed(1)}dB
        </div>
      </div>
    </div>
  );

  return (
    <div className={`${styles.equalizer} ${className || ''}`}>
      <header className={styles.header}>
        <div className={styles.branding}>
          <div className={styles.logo}>BÅNDER</div>
          <div className={styles.esLogo}>ES</div>
        </div>
        <div className={styles.modelInfo}>
          <div className={styles.modelName}>TA-E9000ES</div>
          <div className={styles.modelDesc}>Professional Digital Signal Processor</div>
        </div>
      </header>

      <div className={styles.mainControls}>
        <div className={styles.leftPanel}>
          <div className={styles.sectionTitle}>MASTER CONTROL</div>

          <div className={styles.volumeSection}>
            <div className={styles.knobContainer}>
              <div className={styles.volumeKnob}>
                <input
                  type="range"
                  min="0"
                  max="100"
                  value={volume}
                  onChange={(e) => handleVolumeChange(parseInt(e.target.value))}
                  className={styles.volumeSlider}
                />
                <div className={styles.volumeValue}>{volume}</div>
              </div>
              <div className={styles.knobLabel}>VOLUME</div>
            </div>
          </div>

          <div className={styles.masterButtons}>
            <button
              className={`${styles.controlBtn} ${isMuted ? styles.active : ''}`}
              onClick={handleMuteToggle}
            >
              MUTE
            </button>
            <button
              className={`${styles.controlBtn} ${normalization.enabled ? styles.active : ''}`}
              onClick={handleNormalizationToggle}
            >
              NORM
            </button>
            <button
              className={`${styles.controlBtn} ${eqState.compressionEnabled ? styles.active : ''}`}
              onClick={() => setEqState(prev => ({
                ...prev,
                compressionEnabled: !prev.compressionEnabled
              }))}
            >
              COMP
            </button>
          </div>

          <div className={styles.advancedSection}>
            <div className={styles.paramLabel}>MASTER GAIN</div>
            <input
              type="range"
              min="-12"
              max="12"
              step="0.5"
              value={eqState.masterGain}
              onChange={(e) => handleMasterGainChange(parseFloat(e.target.value))}
              className={styles.paramSlider}
            />
            <div className={styles.paramValue}>
              {eqState.masterGain >= 0 ? '+' : ''}{eqState.masterGain.toFixed(1)} dB
            </div>

            <div className={styles.paramLabel}>LUFS TARGET</div>
            <select
              value={eqState.lufsTarget}
              onChange={(e) => handleLufsTargetChange(parseFloat(e.target.value))}
              className={styles.lufsSelect}
              disabled={!normalization.enabled}
            >
              <option value={-14}>-14 (Spotify)</option>
              <option value={-16}>-16 (Apple Music)</option>
              <option value={-18}>-18 (YouTube)</option>
              <option value={-23}>-23 (Broadcast)</option>
            </select>
          </div>
        </div>

        <div className={styles.centerPanel}>
          {renderVFDDisplay()}

          <div className={styles.displayControls}>
            <button
              className={`${styles.displayBtn} ${eqState.displayMode === 'SPECTRUM' ? styles.active : ''}`}
              onClick={() => setEqState(prev => ({ ...prev, displayMode: 'SPECTRUM' }))}
            >
              SPECTRUM
            </button>
            <button
              className={`${styles.displayBtn} ${eqState.displayMode === 'METERS' ? styles.active : ''}`}
              onClick={() => setEqState(prev => ({ ...prev, displayMode: 'METERS' }))}
            >
              METERS
            </button>
            <button
              className={`${styles.displayBtn} ${eqState.displayMode === 'PHASE' ? styles.active : ''}`}
              onClick={() => setEqState(prev => ({ ...prev, displayMode: 'PHASE' }))}
            >
              PHASE
            </button>
          </div>
        </div>

        <div className={styles.rightPanel}>
          <div className={styles.sectionTitle}>PRESET PROGRAMS</div>
          <div className={styles.presetGrid}>
            {Object.keys(EQ_PRESETS).map(preset => (
              <button
                key={preset}
                className={`${styles.presetBtn} ${eqState.currentPreset === preset ? styles.active : ''}`}
                onClick={() => handlePresetChange(preset as keyof typeof EQ_PRESETS)}
              >
                {preset}
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className={styles.equalizerSection}>
        <div className={styles.sectionTitle}>10-BAND GRAPHIC EQUALIZER</div>
        <div className={styles.eqBands}>
          {eqState.bands.map((band, index) => renderEqualizerBand(band, index))}
        </div>
      </div>

      <div className={styles.statusBar}>
        <div className={styles.statusLeft}>
          <span className={styles.statusLabel}>STATUS:</span>
          <span className={styles.statusValue}>ACTIVE</span>
        </div>
        <div className={styles.statusCenter}>
          <span className={styles.statusLabel}>PRESET:</span>
          <span className={styles.statusValue}>{eqState.currentPreset}</span>
        </div>
        <div className={styles.statusRight}>
          <span className={styles.statusLabel}>TOTAL GAIN:</span>
          <span className={styles.statusValue}>
            {(eqState.masterGain + (normalization.currentGain || 0)).toFixed(1)} dB
          </span>
        </div>
      </div>
    </div>
  );
};