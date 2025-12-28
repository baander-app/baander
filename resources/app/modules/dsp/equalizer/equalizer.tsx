import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { globalAudioProcessor } from '@/app/services/global-audio-processor-service.ts';
import styles from './equalizer.module.scss';
import {
  usePlayerActions,
  usePlayerIsMuted,
  usePlayerIsPlaying,
  usePlayerVolumePercent,
} from '@/app/modules/library-music-player/store';
import {
  useEQSettings,
  useAudioEffects,
  useTargetLufs,
  useVisualizerMode,
  useVolumeNormalization,
  useSettingsStore,
} from '@/app/store/settings';
import { EQ_PRESETS } from '@/app/store/settings/defaults';

interface EqualizerProps {
  className?: string;
}

interface EqualizerBand {
  frequency: number;
  label: string;
  gain: number;
  q: number;
}

const EQ_BANDS: EqualizerBand[] = [
  {frequency: 31.5, label: '31.5', gain: 0, q: 0.7},
  {frequency: 63, label: '63', gain: 0, q: 0.7},
  {frequency: 125, label: '125', gain: 0, q: 0.7},
  {frequency: 250, label: '250', gain: 0, q: 0.7},
  {frequency: 500, label: '500', gain: 0, q: 0.7},
  {frequency: 1000, label: '1K', gain: 0, q: 0.7},
  {frequency: 2000, label: '2K', gain: 0, q: 0.7},
  {frequency: 4000, label: '4K', gain: 0, q: 0.7},
  {frequency: 8000, label: '8K', gain: 0, q: 0.7},
  {frequency: 16000, label: '16K', gain: 0, q: 0.7},
];

// Memoized components
const SpectrumBar = React.memo(({height, index}: { height: number; index: number }) => {
  const color = useMemo(() => {
    if (height > 80) return '#ff4444';
    if (height > 60) return '#ffaa00';
    return '#00ff00';
  }, [height]);

  return (
    <div
      key={index}
      className={styles.spectrumBar}
      style={{
        height: `${Math.max(2, height)}%`,
        backgroundColor: color,
      }}
    />
  );
});

const ChannelMeter = React.memo(({label, level}: { label: string; level: number }) => {
  const color = useMemo(() => {
    if (level > 80) return '#ff4444';
    if (level > 60) return '#ffaa00';
    return '#00ff00';
  }, [level]);

  return (
    <div className={styles.channelMeter}>
      <span className={styles.channelLabel}>{label}</span>
      <div className={styles.meterBar}>
        <div
          className={styles.meterFill}
          style={{
            width: `${Math.min(100, level)}%`,
            backgroundColor: color,
          }}
        />
      </div>
      <span className={styles.levelValue}>{Math.round(level)}</span>
    </div>
  );
});

export const Equalizer: React.FC<EqualizerProps> = ({className}) => {
  // Keep existing timing behavior
  const updateIntervalRef = useRef<number | null>(null);
  const lastDispatchTimeRef = useRef<number>(0);
  const lastLogTimeRef = useRef<number>(0);
  const knobRef = useRef<HTMLDivElement>(null);

  const isMuted = usePlayerIsMuted();
  const isPlaying = usePlayerIsPlaying();
  const volumePercent = usePlayerVolumePercent();

  const {setVolumePercent, toggleMute, setLufs} = usePlayerActions();
  const normalization = useVolumeNormalization();

  // Use individual selectors to avoid duplicate subscriptions
  const equalizer = useEQSettings();
  const effects = useAudioEffects();
  const visualizerMode = useVisualizerMode();
  const targetLufs = useTargetLufs();

  // Get actions directly from store with shallow comparison to prevent infinite loops
  const setEQPreset = useSettingsStore(s => s.setEQPreset);
  const setEQBand = useSettingsStore(s => s.setEQBand);
  const setCompressionEnabled = useSettingsStore(s => s.setCompressionEnabled);
  const setMasterGain = useSettingsStore(s => s.setMasterGain);
  const setTargetLufs = useSettingsStore(s => s.setTargetLufs);
  const setVisualizerMode = useSettingsStore(s => s.setVisualizerMode);

  // Combine EQ_BANDS structure with gain values from settings store
  const bands = useMemo(() =>
      EQ_BANDS.map((band, index) => ({
        ...band,
        gain: equalizer.bands[index],
      })),
    [equalizer.bands],
  );

  // Lightweight display state
  const [displayData, setDisplayData] = useState({
    leftChannel: 0,
    rightChannel: 0,
    lufs: -30,
    peakFrequency: 0,
    rms: 0,
    frequencyBars: new Array(64).fill(0),
  });

  // Smoothing buffer for spectrum bars (keeps original 40ms loop; only smooths values)
  const smoothedBarsRef = useRef<Float32Array>(new Float32Array(64));

  // SINGLE update loop - uses setInterval to match processor frequency exactly
  useEffect(() => {
    const processor = globalAudioProcessor.getProcessor();

    console.log('[Equalizer] processor:', !!processor, 'isPlaying:', isPlaying, 'processor?.isActive:', processor?.isActive);

    if (!processor || !isPlaying) {
      if (updateIntervalRef.current) {
        clearInterval(updateIntervalRef.current);
        updateIntervalRef.current = null;
      }
      return;
    }

    // Match the processor's exact timing - 40ms (25fps)
    updateIntervalRef.current = window.setInterval(() => {
      try {
        const data = processor.getAnalysisData();

        // Log throttled to once per second
        const now = performance.now();
        if (now - lastLogTimeRef.current > 1000) {
          console.log('[Equalizer] Got analysis data:', {
            leftChannel: data?.leftChannel?.toFixed(1),
            rightChannel: data?.rightChannel?.toFixed(1),
            lufs: data?.lufs?.toFixed(1),
            peakFreq: data?.peakFrequency,
            maxFreq: data?.frequencyData ? Math.max(...Array.from(data.frequencyData).slice(0, 100)) : 0,
          });
          lastLogTimeRef.current = now;
        }

        if (data) {
          // Compute spectrum bars with smoothing
          const bars: number[] = [];
          const barCount = 64;
          const alpha = 0.35; // smoothing factor (0..1)
          const decay = 0.92; // decay when no data frame arrives

          if (data.frequencyData && data.frequencyData.length > 0) {
            for (let i = 0; i < barCount; i++) {
              const dataIndex = Math.floor((i / barCount) * data.frequencyData.length);
              const target = (data.frequencyData[dataIndex] / 255) * 100; // 0..100%
              const prev = smoothedBarsRef.current[i] || 0;
              const smoothed = prev * (1 - alpha) + target * alpha;
              smoothedBarsRef.current[i] = smoothed;
              bars.push(smoothed);
            }
          } else {
            // No fresh data: gently decay previous bars to avoid flicker
            for (let i = 0; i < barCount; i++) {
              smoothedBarsRef.current[i] = (smoothedBarsRef.current[i] || 0) * decay;
              bars.push(smoothedBarsRef.current[i]);
            }
          }

          // Update display data from processor outputs
          setDisplayData({
            leftChannel: data.leftChannel,
            rightChannel: data.rightChannel,
            lufs: data.lufs,
            peakFrequency: data.peakFrequency,
            rms: data.rms,
            frequencyBars: bars,
          });

          setLufs(data.lufs);

          // Volume normalization - throttled
          const now = performance.now();
          if (normalization.enabled && data.lufs !== 0 && !isNaN(data.lufs)) {
            if (now - lastDispatchTimeRef.current > 300) {
              // Normalization gain is computed as (targetLufs - currentLufs)
              // The audio processor handles the actual gain application
              lastDispatchTimeRef.current = now;
            }
          }

          processor.setMasterGain(effects.masterGain);
        }
      } catch (error) {
        console.error('Error in analysis update:', error);
      }
    }, 40); // Exactly match processor timing

    return () => {
      if (updateIntervalRef.current) {
        clearInterval(updateIntervalRef.current);
        updateIntervalRef.current = null;
      }
    };
  }, [
    normalization.enabled,
    isPlaying,
    targetLufs,
    effects.masterGain,
    visualizerMode,
    equalizer.enabled,
    equalizer.preset,
  ]);

  // Event handlers
  const handlePresetChange = useCallback((presetName: keyof typeof EQ_PRESETS) => {
    setEQPreset(presetName);
  }, [setEQPreset]);

  const handleBandChange = useCallback((bandIndex: number, gain: number) => {
    setEQBand(bandIndex, gain);
  }, [setEQBand]);

  const handleVolumeChange = useCallback((value: number) => {
    setVolumePercent(value);
  }, [setVolumePercent]);

  const handleMuteToggle = useCallback(() => {
    toggleMute();
  }, [toggleMute]);

  const handleNormalizationToggle = useCallback(() => {
    const newEnabled = !normalization.enabled;
    setCompressionEnabled(newEnabled);
  }, [normalization.enabled, setCompressionEnabled]);

  const handleMasterGainChange = useCallback((value: number) => {
    setMasterGain(value);
  }, [setMasterGain]);

  const handleLufsTargetChange = useCallback((value: number) => {
    setTargetLufs(value as -14 | -16 | -18 | -23);
  }, [setTargetLufs]);

  const handleDisplayModeChange = useCallback((mode: 'SPECTRUM' | 'METERS' | 'PHASE') => {
    setVisualizerMode(mode.toLowerCase() as 'spectrum' | 'meters' | 'phase');
  }, [setVisualizerMode]);

  const handleKnobMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault();

    const handleMouseMove = (moveEvent: MouseEvent) => {
      if (!knobRef.current) return;

      const rect = knobRef.current.getBoundingClientRect();
      const centerX = rect.left + rect.width / 2;
      const centerY = rect.top + rect.height / 2;
      const deltaX = moveEvent.clientX - centerX;
      const deltaY = moveEvent.clientY - centerY;

      let angle = Math.atan2(deltaY, deltaX) * (180 / Math.PI);
      angle = (angle + 90 + 360) % 360;

      const minAngle = 30;
      const maxAngle = 330;
      let normalizedAngle;

      if (angle >= minAngle && angle <= 180) {
        normalizedAngle = angle - minAngle;
      } else if (angle > 180 && angle <= maxAngle) {
        normalizedAngle = angle - minAngle;
      } else {
        normalizedAngle = angle < 180 ? 0 : 300;
      }

      const volumeValue = Math.round((normalizedAngle / 300) * 100);
      const clampedVolume = Math.max(0, Math.min(100, volumeValue));
      handleVolumeChange(clampedVolume);
    };

    const handleMouseUp = () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
    };

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
  }, [handleVolumeChange]);

  // Memoized computed values
  const knobRotation = useMemo(() => volumePercent * 300 + 30, [volumePercent]);

  // Compute current gain for display (targetLufs - currentLufs)
  const currentGain = useMemo(
    () => normalization.enabled ? (targetLufs - displayData.lufs) : 0,
    [normalization.enabled, targetLufs, displayData.lufs],
  );

  const totalGain = useMemo(
    () => (effects.masterGain + currentGain).toFixed(1),
    [effects.masterGain, currentGain],
  );
  const formattedPeakFrequency = useMemo(() => {
    const freq = displayData.peakFrequency ?? 0;
    return freq > 1000 ? `${(freq / 1000).toFixed(1)}K` : `${Math.round(freq)}`;
  }, [displayData.peakFrequency]);

  const phasePath = useMemo(() => {
    const points = 24;
    const pf = displayData.peakFrequency || 0;
    const rms = displayData.rms || 0.1;

    let path = `M 0,50`;
    for (let i = 0; i < points; i++) {
      const x = (i / (points - 1)) * 200;
      const phaseCorrelation = Math.sin((pf / 1000) * i * 0.1) * 30;
      const y = Math.max(10, Math.min(90, 50 + phaseCorrelation * rms));
      path += ` L ${x},${y}`;
    }
    return path;
  }, [displayData.peakFrequency, displayData.rms]);

  const renderSpectrumBars = useMemo(() => {
    return displayData.frequencyBars.map((height, i) => (
      <SpectrumBar key={i} height={height} index={i}/>
    ));
  }, [displayData.frequencyBars]);

  const renderVFDDisplay = useCallback(() => (
    <div className={styles.vfdDisplay}>
      <div className={styles.vfdHeader}>
        <div className={styles.statusIndicators}>
          <span className={`${styles.indicator} ${styles.active}`}>PWR</span>
          <span className={`${styles.indicator} ${isMuted ? styles.active : ''}`}>MUTE</span>
          <span className={`${styles.indicator} ${normalization.enabled ? styles.active : ''}`}>NORM</span>
          <span className={`${styles.indicator} ${equalizer.enabled ? styles.active : ''}`}>PROC</span>
        </div>
        <div className={styles.presetDisplay}>{equalizer.preset}</div>
      </div>

      <div className={styles.vfdContent}>
        <div className={`${styles.displayContainer} ${visualizerMode === 'spectrum' ? styles.active : styles.hidden}`}>
          <div className={styles.spectrumDisplay}>
            {renderSpectrumBars}
          </div>
        </div>

        <div className={`${styles.displayContainer} ${visualizerMode === 'meters' ? styles.active : styles.hidden}`}>
          <div className={styles.levelMeters}>
            <ChannelMeter label="L" level={displayData.leftChannel}/>
            <ChannelMeter label="R" level={displayData.rightChannel}/>
            <div className={styles.frequencyDisplay}>
              <span className={styles.freqLabel}>PEAK:</span>
              <span className={styles.freqValue}>{formattedPeakFrequency}Hz</span>
            </div>
          </div>
        </div>

        <div className={`${styles.displayContainer} ${visualizerMode === 'phase' ? styles.active : styles.hidden}`}>
          <div className={styles.phaseDisplay}>
            <div className={styles.phaseScope}>
              <svg width="100%" height="100%" viewBox="0 0 200 100" aria-hidden="true">
                <defs>
                  <linearGradient id="phaseGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%" stopColor="#00ff00"/>
                    <stop offset="50%" stopColor="#ffff00"/>
                    <stop offset="100%" stopColor="#ff0000"/>
                  </linearGradient>
                </defs>
                <path
                  d={phasePath}
                  stroke="url(#phaseGradient)"
                  strokeWidth="2"
                  fill="none"
                />
              </svg>
            </div>
            <div className={styles.correlationMeter}>
              <span className={styles.correlationLabel}>CORR:</span>
              <span className={styles.correlationValue}>
                {(((displayData.leftChannel ?? 0) + (displayData.rightChannel ?? 0)) / 200).toFixed(2)}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div className={styles.vfdFooter}>
        <div className={styles.lufsDisplay}>
          <span className={styles.lufsLabel}>LUFS:</span>
          <span className={styles.lufsValue}>{(displayData.lufs ?? -30).toFixed(1)}</span>
        </div>
        <div className={styles.volumeDisplay}>
          <span className={styles.volumeLabel}>VOL:</span>
          <span className={styles.volumeValue}>{volumePercent}</span>
        </div>
        <div className={styles.gainDisplay}>
          <span className={styles.gainLabel}>GAIN:</span>
          <span className={styles.gainValue}>{effects.masterGain.toFixed(1)}dB</span>
        </div>
      </div>
    </div>
  ), [isMuted, normalization.enabled, equalizer.enabled, equalizer.preset, visualizerMode, renderSpectrumBars, displayData.leftChannel, displayData.rightChannel, formattedPeakFrequency, phasePath, displayData.lufs, volumePercent, effects.masterGain]);

  const renderEqualizerBand = useCallback((band: EqualizerBand, index: number) => (
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
          aria-label={`${band.label}Hz equalizer band`}
        />
        <div className={styles.gainValue}>
          {band.gain >= 0 ? '+' : ''}
          {band.gain.toFixed(1)}dB
        </div>
      </div>
    </div>
  ), [handleBandChange]);

  return (
    <div style={{position: 'relative', width: '100%', height: '100%'}}>
      <div
        className={`${styles.equalizer} ${className || ''}`}
        style={{boxShadow: '0 10px 30px rgba(0, 0, 0, 0.2)'}}
      >
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
                <div
                  className={styles.volumeKnob}
                  ref={knobRef}
                  onMouseDown={handleKnobMouseDown}
                  role="slider"
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-valuenow={volumePercent}
                  aria-label="Volume control"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === 'ArrowUp' || e.key === 'ArrowRight') {
                      handleVolumeChange(Math.min(100, volumePercent + 5));
                    } else if (e.key === 'ArrowDown' || e.key === 'ArrowLeft') {
                      handleVolumeChange(Math.max(0, volumePercent - 5));
                    }
                  }}
                >
                  <input
                    type="range"
                    min="0"
                    max="100"
                    value={volumePercent}
                    onChange={(e) => handleVolumeChange(parseInt(e.target.value))}
                    className={styles.volumeSlider}
                    aria-label="Volume"
                  />
                  <div
                    className={styles.knobIndicator}
                    style={{
                      transform: `translateX(-50%) rotate(${knobRotation}deg)`,
                    }}
                  />
                  <div className={styles.volumeValue}>{volumePercent}</div>
                </div>
                <div className={styles.knobLabel}>VOLUME</div>
              </div>
            </div>

            <div className={styles.masterButtons}>
              <button
                className={`${styles.controlBtn} ${isMuted ? styles.active : ''}`}
                onClick={handleMuteToggle}
                aria-pressed={isMuted}
              >
                MUTE
              </button>
              <button
                className={`${styles.controlBtn} ${normalization.enabled ? styles.active : ''}`}
                onClick={handleNormalizationToggle}
                aria-pressed={normalization.enabled}
              >
                NORM
              </button>
              <button
                className={`${styles.controlBtn} ${effects.compression.enabled ? styles.active : ''}`}
                onClick={() => setCompressionEnabled(!effects.compression.enabled)}
                aria-pressed={effects.compression.enabled}
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
                value={effects.masterGain}
                onChange={(e) => handleMasterGainChange(parseFloat(e.target.value))}
                className={styles.paramSlider}
                aria-label="Master Gain"
              />
              <div className={styles.paramValue}>
                {effects.masterGain >= 0 ? '+' : ''}
                {effects.masterGain.toFixed(1)} dB
              </div>

              <div className={styles.paramLabel}>LUFS TARGET</div>
              <select
                value={targetLufs}
                onChange={(e) => handleLufsTargetChange(parseFloat(e.target.value))}
                className={styles.lufsSelect}
                disabled={!normalization.enabled}
                aria-label="LUFS Target"
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
                className={`${styles.displayBtn} ${visualizerMode === 'spectrum' ? styles.active : ''}`}
                onClick={() => handleDisplayModeChange('SPECTRUM')}
                aria-pressed={visualizerMode === 'spectrum'}
              >
                SPECTRUM
              </button>
              <button
                className={`${styles.displayBtn} ${visualizerMode === 'meters' ? styles.active : ''}`}
                onClick={() => handleDisplayModeChange('METERS')}
                aria-pressed={visualizerMode === 'meters'}
              >
                METERS
              </button>
              <button
                className={`${styles.displayBtn} ${visualizerMode === 'phase' ? styles.active : ''}`}
                onClick={() => handleDisplayModeChange('PHASE')}
                aria-pressed={visualizerMode === 'phase'}
              >
                PHASE
              </button>
            </div>
          </div>

          <div className={styles.rightPanel}>
            <div className={styles.sectionTitle}>PRESET PROGRAMS</div>
            <div className={styles.presetGrid}>
              {Object.keys(EQ_PRESETS).map((preset) => (
                <button
                  key={preset}
                  className={`${styles.presetBtn} ${equalizer.preset === preset ? styles.active : ''}`}
                  onClick={() => handlePresetChange(preset as keyof typeof EQ_PRESETS)}
                  aria-pressed={equalizer.preset === preset}
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
            {bands.map((band, index) => renderEqualizerBand(band, index))}
          </div>
        </div>

        <div className={styles.statusBar}>
          <div className={styles.statusLeft}>
            <span className={styles.statusLabel}>STATUS:</span>
            <span className={styles.statusValue}>
              {equalizer.enabled ? 'ACTIVE' : 'STANDBY'}
            </span>
          </div>
          <div className={styles.statusCenter}>
            <span className={styles.statusLabel}>PRESET:</span>
            <span className={styles.statusValue}>{equalizer.preset}</span>
          </div>
          <div className={styles.statusRight}>
            <span className={styles.statusLabel}>TOTAL GAIN:</span>
            <span className={styles.statusValue}>{totalGain} dB</span>
          </div>
        </div>
      </div>
    </div>
  );
};
