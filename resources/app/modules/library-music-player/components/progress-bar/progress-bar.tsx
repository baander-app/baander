import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useMusicPlayerStore } from '@/modules/library-music-player/store';
import styles from './progress-bar.module.scss';
import { clamp } from '@/utils/clamp.ts';

interface ProgressBarProps {
  disabled?: boolean;
  showTooltip?: boolean;
  onError?: (error: Error) => void;
  minDuration?: number;
}

interface ProgressState {
  isDragging: boolean;
  dragTime: number;
  hoverTime: number | null;
  tooltipPosition: number;
}

function formatTime(seconds: number): string {
  if (isNaN(seconds) || !isFinite(seconds)) {
    return '0:00';
  }
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

const LoadingState = memo(() => (
  <div className={`${styles.progressSliderContainer} ${styles.isLoading}`} role="progressbar" aria-label="Loading audio">
    <div className={styles.progressTrack}/>
  </div>
));

const ErrorState = memo(({ onRetry }: { onRetry?: () => void }) => (
  <div className={styles.errorState} role="alert" aria-live="polite">
    <span>Unable to load progress</span>
    {onRetry && (
      <button onClick={onRetry} type="button" className={styles.retryButton}>
        Retry
      </button>
    )}
  </div>
));

export function ProgressBar({
                              disabled = false,
                              showTooltip = true,
                              onError,
                              minDuration = 0.1,
                            }: ProgressBarProps) {
  // Read only what the component needs
  const duration = useMusicPlayerStore(s => s.duration);
  const currentTime = useMusicPlayerStore(s => s.currentTime);
  const buffered = useMusicPlayerStore(s => s.buffered);
  const seekTo = useMusicPlayerStore(s => s.seekTo);

  // rAF throttling helpers for move events
  const hoverRafId = useRef<number | null>(null);
  const dragRafId = useRef<number | null>(null);
  const pendingHover = useRef<{ time: number; pos: number } | null>(null);
  const pendingDragTime = useRef<number | null>(null);

  const [progressState, setProgressState] = useState<ProgressState>({
    isDragging: false,
    dragTime: 0,
    hoverTime: null,
    tooltipPosition: 0,
  });

  const [hasError, setHasError] = useState(false);

  const containerRef = useRef<HTMLDivElement>(null);
  const tooltipId = useRef(`progress-tooltip-${Math.random().toString(36).substring(2, 11)}`);

  const isValidDuration = useMemo(
    () => duration > minDuration && isFinite(duration) && !isNaN(duration),
    [duration, minDuration],
  );

  const displayTime = progressState.isDragging ? progressState.dragTime : currentTime;

  const progressMetrics = useMemo(() => {
    if (!isValidDuration) return { displayProgress: 0, bufferedPercentage: 0 };
    return {
      displayProgress: clamp((displayTime / duration) * 100, 0, 100),
      bufferedPercentage: clamp((buffered / duration) * 100, 0, 100),
    };
  }, [displayTime, duration, buffered, isValidDuration]);

  const handleError = useCallback((error: Error, context: string) => {
    console.warn(`Progress bar error (${context}):`, error);
    setHasError(true);
    onError?.(error);
  }, [onError]);

  const calculateTimeFromEvent = useCallback((event: React.MouseEvent | MouseEvent) => {
    try {
      if (!containerRef.current || !isValidDuration) return 0;
      const rect = containerRef.current.getBoundingClientRect();
      if (rect.width === 0) return 0;
      const x = event.clientX - rect.left;
      const percentage = clamp(x / rect.width, 0, 1);
      return percentage * duration;
    } catch (error) {
      handleError(error as Error, 'calculateTimeFromEvent');
      return 0;
    }
  }, [duration, isValidDuration, handleError]);

  const updateProgressState = useCallback((updates: Partial<ProgressState>) => {
    setProgressState(prev => {
      let changed = false;
      const next: ProgressState = { ...prev };
      for (const k in updates) {
        const key = k as keyof ProgressState;
        if (updates[key] !== undefined && updates[key] !== prev[key]) {
          // @ts-expect-error index signature
          next[key] = updates[key];
          changed = true;
        }
      }
      return changed ? next : prev;
    });
  }, []);

  const seekToTime = useCallback((seekTime: number) => {
    try {
      const clampedTime = clamp(seekTime, 0, duration);
      // Drive the player via store; it updates element and state
      seekTo(clampedTime);
    } catch (error) {
      handleError(error as Error, 'seekToTime');
    }
  }, [duration, seekTo, handleError]);

  const handleMouseDown = useCallback((event: React.MouseEvent<HTMLDivElement>) => {
    if (disabled || !isValidDuration) return;
    try {
      event.preventDefault();
      const time = calculateTimeFromEvent(event);
      updateProgressState({ isDragging: true, dragTime: time });
    } catch (error) {
      handleError(error as Error, 'handleMouseDown');
    }
  }, [disabled, isValidDuration, calculateTimeFromEvent, updateProgressState, handleError]);

  const handleContainerMouseMove = useCallback((event: React.MouseEvent<HTMLDivElement>) => {
    if (!isValidDuration || !showTooltip) return;
    try {
      const time = calculateTimeFromEvent(event);
      const percentage = (time / duration) * 100;
      pendingHover.current = { time, pos: clamp(percentage, 0, 100) };
      if (hoverRafId.current == null) {
        hoverRafId.current = requestAnimationFrame(() => {
          hoverRafId.current = null;
          const payload = pendingHover.current;
          if (!payload) return;
          pendingHover.current = null;
          updateProgressState({
            hoverTime: payload.time,
            tooltipPosition: payload.pos,
          });
        });
      }
    } catch (error) {
      handleError(error as Error, 'handleContainerMouseMove');
    }
  }, [isValidDuration, calculateTimeFromEvent, duration, showTooltip, updateProgressState, handleError]);

  const handleGlobalMouseMove = useCallback((event: MouseEvent) => {
    if (!progressState.isDragging || !isValidDuration) return;
    try {
      const time = calculateTimeFromEvent(event);
      pendingDragTime.current = time;
      if (dragRafId.current == null) {
        dragRafId.current = requestAnimationFrame(() => {
          dragRafId.current = null;
          const t = pendingDragTime.current;
          if (t == null) return;
          pendingDragTime.current = null;
          updateProgressState({ dragTime: t });
        });
      }
    } catch (error) {
      handleError(error as Error, 'handleGlobalMouseMove');
    }
  }, [progressState.isDragging, isValidDuration, calculateTimeFromEvent, updateProgressState, handleError]);

  const handleMouseUp = useCallback((event: MouseEvent) => {
    if (!progressState.isDragging || !isValidDuration) return;
    try {
      if (dragRafId.current != null) {
        cancelAnimationFrame(dragRafId.current);
        dragRafId.current = null;
      }
      const seekTime = calculateTimeFromEvent(event);
      updateProgressState({ isDragging: false });
      seekToTime(seekTime);
    } catch (error) {
      handleError(error as Error, 'handleMouseUp');
    }
  }, [progressState.isDragging, isValidDuration, calculateTimeFromEvent, updateProgressState, seekToTime, handleError]);

  const handleMouseLeave = useCallback(() => {
    updateProgressState({ hoverTime: null });
  }, [updateProgressState]);

  const handleKeyDown = useCallback((event: React.KeyboardEvent<HTMLDivElement>) => {
    if (disabled || !isValidDuration) return;
    try {
      const step = 5;
      let newTime = currentTime;
      switch (event.key) {
        case 'ArrowLeft': event.preventDefault(); newTime = Math.max(0, currentTime - step); break;
        case 'ArrowRight': event.preventDefault(); newTime = Math.min(duration, currentTime + step); break;
        case 'Home': event.preventDefault(); newTime = 0; break;
        case 'End': event.preventDefault(); newTime = duration; break;
        default: return;
      }
      seekToTime(newTime);
    } catch (error) {
      handleError(error as Error, 'handleKeyDown');
    }
  }, [disabled, isValidDuration, currentTime, duration, seekToTime, handleError]);

  const handleClick = useCallback((event: React.MouseEvent<HTMLDivElement>) => {
    if (disabled || !isValidDuration || progressState.isDragging) return;
    try {
      const seekTime = calculateTimeFromEvent(event);
      seekToTime(seekTime);
    } catch (error) {
      handleError(error as Error, 'handleClick');
    }
  }, [disabled, isValidDuration, progressState.isDragging, calculateTimeFromEvent, seekToTime, handleError]);

  const handleRetry = useCallback(() => {
    setHasError(false);
  }, []);

  useEffect(() => {
    if (!progressState.isDragging) return;
    const abortController = new AbortController();
    document.addEventListener('mousemove', handleGlobalMouseMove, { signal: abortController.signal, passive: true });
    document.addEventListener('mouseup', handleMouseUp, { signal: abortController.signal });
    return () => {
      if (dragRafId.current != null) {
        cancelAnimationFrame(dragRafId.current);
        dragRafId.current = null;
        pendingDragTime.current = null;
      }
      abortController.abort();
    };
  }, [progressState.isDragging, handleGlobalMouseMove, handleMouseUp]);

  const containerClasses = useMemo(() => [
    styles.progressSliderContainer,
    disabled && styles.isDisabled,
    !isValidDuration && styles.isLoading,
    hasError && styles.hasError,
  ].filter(Boolean).join(' '), [disabled, isValidDuration, hasError]);

  const thumbClasses = useMemo(() => [
    styles.progressThumb,
    progressState.isDragging && styles.isDragging,
  ].filter(Boolean).join(' '), [progressState.isDragging]);

  if (hasError) return <ErrorState onRetry={handleRetry}/>;
  if (!isValidDuration) return <LoadingState/>;

  return (
    <div
      ref={containerRef}
      className={containerClasses}
      onMouseDown={handleMouseDown}
      onClick={handleClick}
      onMouseMove={handleContainerMouseMove}
      onMouseLeave={handleMouseLeave}
      onKeyDown={handleKeyDown}
      tabIndex={disabled ? -1 : 0}
      role="slider"
      aria-label={`Progress: ${formatTime(currentTime)} of ${formatTime(duration)}`}
      aria-describedby={showTooltip ? tooltipId.current : undefined}
      aria-valuemin={0}
      aria-valuemax={duration}
      aria-valuenow={currentTime}
      aria-valuetext={`${formatTime(currentTime)} of ${formatTime(duration)}`}
      aria-orientation="horizontal"
      data-testid="progress-bar-container"
      data-current-time={currentTime}
      data-duration={duration}
    >
      <div className={styles.progressTrack}>
        <div className={styles.bufferedProgress} style={{ width: `${progressMetrics.bufferedPercentage}%` }}/>
        <div className={styles.currentProgress} style={{ width: `${progressMetrics.displayProgress}%` }}/>
      </div>

      <div className={thumbClasses} style={{ left: `${progressMetrics.displayProgress}%` }} aria-hidden="true" />

      {showTooltip && progressState.hoverTime !== null && (
        <div
          id={tooltipId.current}
          className={styles.timeTooltip}
          style={{ left: `${clamp(progressState.tooltipPosition, 5, 95)}%` }}
          role="tooltip"
        >
          {formatTime(progressState.hoverTime)}
        </div>
      )}

      <input
        type="range"
        className={styles.hiddenInput}
        min={0}
        max={duration}
        step={0.1}
        value={currentTime}
        disabled={disabled}
        onChange={() => {}}
        tabIndex={-1}
        aria-hidden="true"
      />
    </div>
  );
}
