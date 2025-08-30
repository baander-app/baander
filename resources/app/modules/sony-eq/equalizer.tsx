import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useAppDispatch, useAppSelector } from '@/store/hooks';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';
import { globalAudioProcessor } from '@/services/global-audio-processor-service.ts';
import { setLufs, setNormalizationGain, setVolumeNormalization } from '@/store/music/music-player-slice';
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

interface Position {
  x: number;
  y: number;
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
  { frequency: 16000, label: '16K', gain: 0, q: 0.7 },
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
  'CUSTOM': [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
};

const SpectrumBar = React.memo(({ height, index }: { height: number; index: number }) => {
  const color = height > 80 ? '#ff4444' : height > 60 ? '#ffaa00' : '#00ff00';
  return (
    <div
      key={index}
      className={styles.spectrumBar}
      style={{ height: `${Math.max(2, height)}%`, backgroundColor: color }}
    />
  );
});

const ChannelMeter = React.memo(({ label, level }: { label: string; level: number }) => {
  const color = level > 80 ? '#ff4444' : level > 60 ? '#ffaa00' : '#00ff00';
  return (
    <div className={styles.channelMeter}>
      <span className={styles.channelLabel}>{label}</span>
      <div className={styles.meterBar}>
        <div
          className={styles.meterFill}
          style={{ width: `${Math.min(100, level)}%`, backgroundColor: color }}
        />
      </div>
      <span className={styles.levelValue}>{Math.round(level)}</span>
    </div>
  );
});

export const Equalizer: React.FC<EqualizerProps> = ({ className }) => {
  const dispatch = useAppDispatch();
  const animationFrame = useRef<number | undefined>(undefined);
  const knobRef = useRef<HTMLDivElement>(null);
  const equalizerRef = useRef<HTMLDivElement>(null);
  const headerRef = useRef<HTMLDivElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const audioProcessor = useRef<ReturnType<typeof globalAudioProcessor.getProcessor> | null>(null);
  const frameCount = useRef<number>(0);
  const lastDispatchTime = useRef<number>(0);
  const dragOffset = useRef<Position>({ x: 0, y: 0 });

  const { volume, setCurrentVolume, isMuted, toggleMuteUnmute, isPlaying } = useAudioPlayer();
  const { volume: volumeState } = useAppSelector(state => state.musicPlayer);
  const normalization = volumeState.normalization;

  const [eqState, setEqState] = useState({
    isEnabled: true,
    currentPreset: 'FLAT',
    bands: [...EQ_BANDS],
    displayMode: 'SPECTRUM' as 'SPECTRUM' | 'METERS' | 'PHASE',
    compressionEnabled: true,
    spatialEnhancement: false,
    masterGain: 0,
    lufsTarget: -16,
    processorActive: false,
    processorMode: 'direct' as 'passive' | 'direct',
  });

  const [position, setPosition] = useState<Position>({ x: 0, y: 0 });
  const [scale, setScale] = useState<number>(1);
  const [isDragging, setIsDragging] = useState<boolean>(false);

  const [analysisData, setAnalysisData] = useState<AnalysisData>(() => ({
    frequencyData: new Uint8Array(1024),
    timeDomainData: new Uint8Array(2048),
    leftChannel: 0,
    rightChannel: 0,
    lufs: -30,
    peakFrequency: 0,
    rms: 0,
  }));

  const centerWithinContainer = useCallback(() => {
    if (!containerRef.current || !equalizerRef.current) return;
    const containerRect = containerRef.current.getBoundingClientRect();
    const eqRect = equalizerRef.current.getBoundingClientRect();
    const x = Math.max(0, (containerRect.width - eqRect.width) / 2);
    const y = Math.max(0, (containerRect.height - eqRect.height) / 2);
    setPosition({ x, y });
  }, []);

  useEffect(() => {
    const handleResize = () => {
      if (!containerRef.current || !equalizerRef.current) return;
      const containerRect = containerRef.current.getBoundingClientRect();
      const eqRect = equalizerRef.current.getBoundingClientRect();
      const newX = Math.max(0, Math.min(position.x, containerRect.width - eqRect.width));
      const newY = Math.max(0, Math.min(position.y, containerRect.height - eqRect.height));
      if (newX !== position.x || newY !== position.y) setPosition({ x: newX, y: newY });
      const idealWidth = 800;
      const idealHeight = 600;
      const widthRatio = containerRect.width / idealWidth;
      const heightRatio = containerRect.height / idealHeight;
      const newScale = Math.min(1, Math.min(widthRatio * 0.9, heightRatio * 0.9));
      if (newScale !== scale) setScale(newScale);
      if (position.x === 0 && position.y === 0) centerWithinContainer();
    };
    const ro = new ResizeObserver(handleResize);
    if (containerRef.current) ro.observe(containerRef.current);
    handleResize();
    return () => ro.disconnect();
  }, [position, scale, centerWithinContainer]);

  useEffect(() => {
    audioProcessor.current = globalAudioProcessor.getProcessor();
    if (audioProcessor.current && isPlaying) {
      frameCount.current = 0;
      const updateAnalysis = () => {
        frameCount.current++;
        try {
          if (!audioProcessor.current) return;
          const isActive = audioProcessor.current.isActive;
          const data = audioProcessor.current.getAnalysisData();
          const processorMode = (audioProcessor.current as any).passiveMode ? 'passive' : 'direct';
          if (isActive !== eqState.processorActive || processorMode !== eqState.processorMode) {
            setEqState(prev => ({ ...prev, processorActive: isActive, processorMode }));
          }
          if (data) {
            setAnalysisData(prevData => ({
              ...data,
              frequencyData: data.frequencyData.length > 0 ? data.frequencyData : prevData.frequencyData,
              timeDomainData: data.timeDomainData.length > 0 ? data.timeDomainData : prevData.timeDomainData,
            }));
            dispatch(setLufs(data.lufs));
            const now = performance.now();
            if (normalization.enabled && data.lufs !== 0 && !isNaN(data.lufs)) {
              const targetLufs = eqState.lufsTarget;
              const currentLufs = data.lufs;
              const gainDifference = targetLufs - currentLufs;
              const maxGainAdjustment = 6;
              const limitedGain = Math.max(-maxGainAdjustment, Math.min(maxGainAdjustment, gainDifference));
              if (now - lastDispatchTime.current > 300) {
                const previousGain = normalization.currentGain || 0;
                const smoothingFactor = 0.1;
                const smoothedGain = previousGain + (limitedGain - previousGain) * smoothingFactor;
                dispatch(setNormalizationGain(smoothedGain));
                lastDispatchTime.current = now;
              }
            } else if (!normalization.enabled && normalization.currentGain !== 0) {
              dispatch(setNormalizationGain(0));
            }
            audioProcessor.current.setMasterGain(eqState.masterGain);
          }
        } catch (error) {
          console.error('Error in analysis update:', error);
        }
        if (isPlaying) animationFrame.current = requestAnimationFrame(updateAnalysis);
      };
      updateAnalysis();
    }
    return () => {
      if (animationFrame.current) {
        cancelAnimationFrame(animationFrame.current);
        animationFrame.current = undefined;
      }
    };
  }, [dispatch, normalization.enabled, isPlaying, eqState.lufsTarget, eqState.masterGain, normalization.currentGain, eqState.processorActive, eqState.processorMode]);

  useEffect(() => {
    const processor = globalAudioProcessor.getProcessor();
    if (processor) {
      processor.setEnabled();
      processor.updateEQBands(eqState.bands.map(band => band.gain));
      processor.setVolume(volume / 100);
      processor.setMuted(isMuted);
      processor.setCompression(eqState.compressionEnabled);
      processor.setSpatialEnhancement(eqState.spatialEnhancement);
      processor.setMasterGain(eqState.masterGain);
    }
  }, [eqState.bands, eqState.compressionEnabled, eqState.spatialEnhancement, eqState.masterGain, volume, isMuted]);

  const handleHeaderMouseDown = useCallback((e: React.MouseEvent) => {
    if (!containerRef.current || !equalizerRef.current) return;
    e.preventDefault();
    setIsDragging(true);
    const eqRect = equalizerRef.current.getBoundingClientRect();
    const containerRect = containerRef.current.getBoundingClientRect();
    dragOffset.current = { x: e.clientX - eqRect.left + containerRect.left, y: e.clientY - eqRect.top + containerRect.top };
  }, []);

  useEffect(() => {
    const handleMouseMove = (e: MouseEvent) => {
      if (!isDragging || !containerRef.current || !equalizerRef.current) return;
      const containerRect = containerRef.current.getBoundingClientRect();
      const eqRect = equalizerRef.current.getBoundingClientRect();
      const newX = e.clientX - dragOffset.current.x;
      const newY = e.clientY - dragOffset.current.y;
      const boundedX = Math.max(0, Math.min(newX, containerRect.width - eqRect.width));
      const boundedY = Math.max(0, Math.min(newY, containerRect.height - eqRect.height));
      setPosition({ x: boundedX, y: boundedY });
    };
    const handleMouseUp = () => setIsDragging(false);
    if (isDragging) {
      document.addEventListener('mousemove', handleMouseMove);
      document.addEventListener('mouseup', handleMouseUp);
    }
    return () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
    };
  }, [isDragging]);

  const increaseScale = useCallback(() => setScale(prev => Math.min(1.5, prev + 0.1)), []);
  const decreaseScale = useCallback(() => setScale(prev => Math.max(0.5, prev - 0.1)), []);
  const resetScale = useCallback(() => {
    setScale(1);
    centerWithinContainer();
  }, [centerWithinContainer]);

  const handlePresetChange = useCallback((presetName: keyof typeof EQ_PRESETS) => {
    const presetValues = EQ_PRESETS[presetName];
    setEqState(prev => ({
      ...prev,
      currentPreset: presetName,
      bands: prev.bands.map((band, index) => ({ ...band, gain: presetValues[index] || 0 })),
    }));
  }, []);

  const handleBandChange = useCallback((bandIndex: number, gain: number) => {
    setEqState(prev => ({
      ...prev,
      currentPreset: 'CUSTOM',
      bands: prev.bands.map((band, index) => (index === bandIndex ? { ...band, gain } : band)),
    }));
  }, []);

  const handleVolumeChange = useCallback((value: number) => setCurrentVolume(value), [setCurrentVolume]);
  const handleMuteToggle = useCallback(() => toggleMuteUnmute(), [toggleMuteUnmute]);

  const handleNormalizationToggle = useCallback(() => {
    const newEnabled = !normalization.enabled;
    dispatch(setVolumeNormalization(newEnabled));
    if (!newEnabled) dispatch(setNormalizationGain(0));
  }, [dispatch, normalization.enabled]);

  const handleMasterGainChange = useCallback((value: number) => {
    setEqState(prev => ({ ...prev, masterGain: value }));
  }, []);

  const handleLufsTargetChange = useCallback((value: number) => {
    setEqState(prev => ({ ...prev, lufsTarget: value }));
    if (normalization.enabled) dispatch(setNormalizationGain(0));
  }, [dispatch, normalization.enabled]);

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
      if (angle >= minAngle && angle <= 180) normalizedAngle = angle - minAngle;
      else if (angle > 180 && angle <= maxAngle) normalizedAngle = angle - minAngle;
      else normalizedAngle = angle < 180 ? 0 : 300;
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

  const knobRotation = useMemo(() => (volume / 100) * 300 + 30, [volume]);
  const totalGain = useMemo(() => (eqState.masterGain + (normalization.currentGain || 0)).toFixed(1), [eqState.masterGain, normalization.currentGain]);
  const formattedPeakFrequency = useMemo(() => (analysisData.peakFrequency > 1000 ? `${(analysisData.peakFrequency / 1000).toFixed(1)}K` : `${Math.round(analysisData.peakFrequency)}`), [analysisData.peakFrequency]);

  const renderVFDDisplay = () => (
    <div className={styles.vfdDisplay}>
      <div className={styles.vfdHeader}>
        <div className={styles.statusIndicators}>
          <span className={`${styles.indicator} ${styles.active}`}>PWR</span>
          <span className={`${styles.indicator} ${isMuted ? styles.active : ''}`}>MUTE</span>
          <span className={`${styles.indicator} ${normalization.enabled ? styles.active : ''}`}>NORM</span>
          <span className={`${styles.indicator} ${eqState.processorActive ? styles.active : ''}`}>PROC</span>
        </div>
        <div className={styles.presetDisplay}>{eqState.currentPreset}</div>
      </div>
      <div className={styles.vfdContent}>
        <div className={`${styles.displayContainer} ${eqState.displayMode === 'SPECTRUM' ? styles.active : styles.hidden}`}>
          <div className={styles.spectrumDisplay}>
            {Array.from({ length: 64 }, (_, i) => {
              const dataIndex = Math.floor((i / 64) * analysisData.frequencyData.length);
              const height = (analysisData.frequencyData[dataIndex] / 255) * 100;
              return <SpectrumBar key={i} height={height} index={i} />;
            })}
          </div>
        </div>
        <div className={`${styles.displayContainer} ${eqState.displayMode === 'METERS' ? styles.active : styles.hidden}`}>
          <div className={styles.levelMeters}>
            <ChannelMeter label="L" level={analysisData.leftChannel} />
            <ChannelMeter label="R" level={analysisData.rightChannel} />
            <div className={styles.frequencyDisplay}>
              <span className={styles.freqLabel}>PEAK:</span>
              <span className={styles.freqValue}>{formattedPeakFrequency}Hz</span>
            </div>
          </div>
        </div>
        <div className={`${styles.displayContainer} ${eqState.displayMode === 'PHASE' ? styles.active : styles.hidden}`}>
          <div className={styles.phaseDisplay}>
            <div className={styles.phaseScope}>
              <svg width="100%" height="100%" viewBox="0 0 200 100" aria-hidden="true">
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
              <span className={styles.correlationValue}>{((analysisData.leftChannel + analysisData.rightChannel) / 200).toFixed(2)}</span>
            </div>
          </div>
        </div>
      </div>
      <div className={styles.vfdFooter}>
        <div className={styles.lufsDisplay}>
          <span className={styles.lufsLabel}>LUFS:</span>
          <span className={styles.lufsValue}>{analysisData.lufs.toFixed(1)}</span>
        </div>
        <div className={styles.volumeDisplay}>
          <span className={styles.volumeLabel}>VOL:</span>
          <span className={styles.volumeValue}>{volume}</span>
        </div>
        <div className={styles.gainDisplay}>
          <span className={styles.gainLabel}>GAIN:</span>
          <span className={styles.gainValue}>{eqState.masterGain.toFixed(1)}dB</span>
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
          onChange={e => handleBandChange(index, parseFloat(e.target.value))}
          className={styles.slider}
          aria-label={`${band.label}Hz equalizer band`}
        />
        <div className={styles.gainValue}>{band.gain >= 0 ? '+' : ''}{band.gain.toFixed(1)}dB</div>
      </div>
    </div>
  );

  return (
    <div ref={containerRef} style={{ position: 'relative', width: '100%', height: '100%' }}>
      <div
        ref={equalizerRef}
        className={`${styles.equalizer} ${className || ''}`}
        style={{
          transform: `scale(${scale})`,
          position: 'absolute',
          left: `${position.x}px`,
          top: `${position.y}px`,
          transformOrigin: 'top left',
          boxShadow: '0 10px 30px rgba(0, 0, 0, 0.2)',
          cursor: isDragging ? 'grabbing' : 'default',
          transition: isDragging ? 'none' : 'transform 0.2s ease',
        }}
      >
        <header
          ref={headerRef}
          className={styles.header}
          onMouseDown={handleHeaderMouseDown}
          style={{ cursor: isDragging ? 'grabbing' : 'grab' }}
        >
          <div className={styles.branding}>
            <div className={styles.logo}>BÅNDER</div>
            <div className={styles.esLogo}>ES</div>
          </div>
          <div className={styles.modelInfo}>
            <div className={styles.modelName}>TA-E9000ES</div>
            <div className={styles.modelDesc}>Professional Digital Signal Processor</div>
          </div>
          <div className={styles.controlButtons}>
            <button className={styles.scaleButton} onClick={increaseScale} aria-label="Increase size">+</button>
            <button className={styles.scaleButton} onClick={resetScale} aria-label="Reset size and position">↺</button>
            <button className={styles.scaleButton} onClick={decreaseScale} aria-label="Decrease size">-</button>
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
                  aria-valuenow={volume}
                  aria-label="Volume control"
                  tabIndex={0}
                  onKeyDown={e => {
                    if (e.key === 'ArrowUp' || e.key === 'ArrowRight') handleVolumeChange(Math.min(100, volume + 5));
                    else if (e.key === 'ArrowDown' || e.key === 'ArrowLeft') handleVolumeChange(Math.max(0, volume - 5));
                  }}
                >
                  <input
                    type="range"
                    min="0"
                    max="100"
                    value={volume}
                    onChange={e => handleVolumeChange(parseInt(e.target.value))}
                    className={styles.volumeSlider}
                    aria-label="Volume"
                  />
                  <div className={styles.knobIndicator} style={{ transform: `translateX(-50%) rotate(${knobRotation}deg)` }} />
                  <div className={styles.volumeValue}>{volume}</div>
                </div>
                <div className={styles.knobLabel}>VOLUME</div>
              </div>
            </div>

            <div className={styles.masterButtons}>
              <button className={`${styles.controlBtn} ${isMuted ? styles.active : ''}`} onClick={handleMuteToggle} aria-pressed={isMuted}>MUTE</button>
              <button className={`${styles.controlBtn} ${normalization.enabled ? styles.active : ''}`} onClick={handleNormalizationToggle} aria-pressed={normalization.enabled}>NORM</button>
              <button
                className={`${styles.controlBtn} ${eqState.compressionEnabled ? styles.active : ''}`}
                onClick={() => setEqState(prev => ({ ...prev, compressionEnabled: !prev.compressionEnabled }))}
                aria-pressed={eqState.compressionEnabled}
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
                onChange={e => handleMasterGainChange(parseFloat(e.target.value))}
                className={styles.paramSlider}
                aria-label="Master Gain"
              />
              <div className={styles.paramValue}>{eqState.masterGain >= 0 ? '+' : ''}{eqState.masterGain.toFixed(1)} dB</div>

              <div className={styles.paramLabel}>LUFS TARGET</div>
              <select
                value={eqState.lufsTarget}
                onChange={e => handleLufsTargetChange(parseFloat(e.target.value))}
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
                className={`${styles.displayBtn} ${eqState.displayMode === 'SPECTRUM' ? styles.active : ''}`}
                onClick={() => setEqState(prev => ({ ...prev, displayMode: 'SPECTRUM' }))}
                aria-pressed={eqState.displayMode === 'SPECTRUM'}
              >
                SPECTRUM
              </button>
              <button
                className={`${styles.displayBtn} ${eqState.displayMode === 'METERS' ? styles.active : ''}`}
                onClick={() => setEqState(prev => ({ ...prev, displayMode: 'METERS' }))}
                aria-pressed={eqState.displayMode === 'METERS'}
              >
                METERS
              </button>
              <button
                className={`${styles.displayBtn} ${eqState.displayMode === 'PHASE' ? styles.active : ''}`}
                onClick={() => setEqState(prev => ({ ...prev, displayMode: 'PHASE' }))}
                aria-pressed={eqState.displayMode === 'PHASE'}
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
                  aria-pressed={eqState.currentPreset === preset}
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
            <span className={styles.statusValue}>{eqState.processorActive ? 'ACTIVE' : 'STANDBY'}</span>
          </div>
          <div className={styles.statusCenter}>
            <span className={styles.statusLabel}>PRESET:</span>
            <span className={styles.statusValue}>{eqState.currentPreset}</span>
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
