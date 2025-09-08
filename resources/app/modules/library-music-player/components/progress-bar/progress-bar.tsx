import { memo, startTransition, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useMusicPlayerStore } from '@/modules/library-music-player/store';
import styles from './progress-bar.module.scss';
import { clamp } from '@/utils/clamp.ts';
import { Flex } from '@radix-ui/themes';

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
  <div className={`${styles.progressSliderContainer} ${styles.isLoading}`} role="progressbar"
       aria-label="Loading audio">
    <div className={styles.progressTrack}/>
  </div>
));

const ErrorState = memo(({ onRetry }: { onRetry?: () => void }) => (
  <Flex className={styles.errorState} role="alert" aria-live="polite">
    <span>Unable to load progress</span>
    {onRetry && (
      <button onClick={onRetry} type="button" className={styles.retryButton}>
        Retry
      </button>
    )}
  </Flex>
));

export const ProgressBar = memo(function ProgressBar({
                                                       disabled = false,
                                                       showTooltip = true,
                                                       onError,
                                                       minDuration = 0.1,
                                                     }: ProgressBarProps) {
  const duration = useMusicPlayerStore(s => s.duration);
  const currentTime = useMusicPlayerStore(s => s.currentTime);
  const buffered = useMusicPlayerStore(s => s.buffered);
  const seekTo = useMusicPlayerStore(s => s.seekTo);

  // DOM References
  const containerRef = useRef<HTMLDivElement>(null);
  const progressTrackRef = useRef<HTMLDivElement>(null);
  const bufferedProgressRef = useRef<HTMLDivElement>(null);
  const currentProgressRef = useRef<HTMLDivElement>(null);
  const thumbRef = useRef<HTMLDivElement>(null);
  const tooltipRef = useRef<HTMLDivElement>(null);

  // Animation and state references
  const hoverRafId = useRef<number | null>(null);
  const dragRafId = useRef<number | null>(null);
  const pendingHover = useRef<{ time: number; pos: number } | null>(null);
  const pendingDragTime = useRef<number | null>(null);
  const animationFrameId = useRef<number | null>(null);
  const updateIntervalId = useRef<number | null>(null);
  const isDevToolsOpen = useRef(false);

  // Track state outside React to minimize updates
  const currentMetrics = useRef({
    displayProgress: 0,
    bufferedPercentage: 0,
    smoothedTime: currentTime,
    lastUpdateTime: 0
  });

  const tooltipId = useRef(`progress-tooltip-${Math.random().toString(36).substring(2, 11)}`);

  // Minimized React state that requires re-renders
  const [progressState, setProgressState] = useState<ProgressState>({
    isDragging: false,
    dragTime: 0,
    hoverTime: null,
    tooltipPosition: 0,
  });
  const [hasError, setHasError] = useState(false);

  const isValidDuration = useMemo(
    () => duration > minDuration && isFinite(duration) && !isNaN(duration),
    [duration, minDuration],
  );

  // Check if DevTools is open
  useEffect(() => {
    const checkDevTools = () => {
      const heightDiff = window.outerHeight - window.innerHeight > 200;
      const widthDiff = window.outerWidth - window.innerWidth > 200;
      isDevToolsOpen.current = heightDiff || widthDiff;
    };

    checkDevTools();
    const interval = setInterval(checkDevTools, 2000);
    return () => clearInterval(interval);
  }, []);

  // Apply progress directly to DOM elements
  const applyProgressToDOM = useCallback((displayProgress: number, bufferedPercentage: number) => {
    if (!thumbRef.current || !currentProgressRef.current || !bufferedProgressRef.current) return;

    // Convert percentage to pixels for thumb position to ensure proper positioning
    if (progressTrackRef.current) {
      const trackWidth = progressTrackRef.current.offsetWidth;
      const positionInPixels = (displayProgress / 100) * trackWidth;

      // Set left position directly instead of using transform/CSS variables
      thumbRef.current.style.left = `${positionInPixels}px`;
    }

    // Set width directly for progress bars
    currentProgressRef.current.style.width = `${displayProgress}%`;
    bufferedProgressRef.current.style.width = `${bufferedPercentage}%`;

    // Update accessibility attributes
    if (containerRef.current) {
      containerRef.current.setAttribute('aria-valuenow', String(currentTime));
      containerRef.current.setAttribute('aria-valuetext', `${formatTime(currentTime)} of ${formatTime(duration)}`);
    }
  }, [currentTime, duration]);

  // Update tooltip position
  const updateTooltip = useCallback((hoverTime: number | null, position: number) => {
    if (!tooltipRef.current) return;

    if (hoverTime === null) {
      tooltipRef.current.style.display = 'none';
    } else {
      tooltipRef.current.style.display = 'block';
      tooltipRef.current.style.left = `${clamp(position, 5, 95)}%`;
      tooltipRef.current.textContent = formatTime(hoverTime);
    }
  }, []);

  // Calculate and update progress metrics
  const updateProgressMetrics = useCallback(() => {
    if (!isValidDuration) return;

    // Determine current display time
    const displayTime = progressState.isDragging
                        ? progressState.dragTime
                        : currentMetrics.current.smoothedTime;

    // Calculate new values
    const newDisplayProgress = clamp((displayTime / duration) * 100, 0, 100);
    const newBufferedPercentage = clamp((buffered / duration) * 100, 0, 100);

    // Check for significant change before updating DOM
    const displayDiff = Math.abs(newDisplayProgress - currentMetrics.current.displayProgress);
    const bufferedDiff = Math.abs(newBufferedPercentage - currentMetrics.current.bufferedPercentage);

    // Use higher threshold when DevTools open
    const threshold = isDevToolsOpen.current ? 1.0 : 0.2;

    // Only update DOM if change is significant or user is dragging
    if (displayDiff > threshold || bufferedDiff > threshold || progressState.isDragging) {
      // Throttle updates when DevTools is open
      const now = Date.now();
      if (isDevToolsOpen.current && now - currentMetrics.current.lastUpdateTime < 200 && !progressState.isDragging) {
        return;
      }

      // Update metrics reference
      currentMetrics.current.displayProgress = newDisplayProgress;
      currentMetrics.current.bufferedPercentage = newBufferedPercentage;
      currentMetrics.current.lastUpdateTime = now;

      // Apply directly to DOM
      applyProgressToDOM(newDisplayProgress, newBufferedPercentage);
    }
  }, [duration, buffered, progressState.isDragging, progressState.dragTime, isValidDuration, applyProgressToDOM]);

  // Smooth animation loop for current time
  useEffect(() => {
    if (progressState.isDragging || !isValidDuration) return;

    // Clear existing animations/intervals
    if (animationFrameId.current !== null) {
      cancelAnimationFrame(animationFrameId.current);
      animationFrameId.current = null;
    }

    if (updateIntervalId.current !== null) {
      clearInterval(updateIntervalId.current);
      updateIntervalId.current = null;
    }

    // Smoothly update time with animation
    const updateSmoothTime = () => {
      // Skip if change is tiny
      if (Math.abs(currentMetrics.current.smoothedTime - currentTime) < 0.05) {
        return;
      }

      // Apply smoothing factor
      const delta = (currentTime - currentMetrics.current.smoothedTime) * 0.2;

      // Snap to target when close
      if (Math.abs(delta) < 0.01) {
        currentMetrics.current.smoothedTime = currentTime;
      } else {
        currentMetrics.current.smoothedTime += delta;
      }

      // Update the DOM
      updateProgressMetrics();
    };

    // Use different update strategies based on DevTools state
    if (isDevToolsOpen.current) {
      // Less frequent updates when DevTools open
      updateIntervalId.current = window.setInterval(updateSmoothTime, 250);
    } else {
      // Normal animation loop
      const animate = () => {
        updateSmoothTime();

        // Continue animation if needed
        if (Math.abs(currentMetrics.current.smoothedTime - currentTime) > 0.01) {
          animationFrameId.current = requestAnimationFrame(animate);
        }
      };

      // Start animation
      animationFrameId.current = requestAnimationFrame(animate);
    }

    // Cleanup
    return () => {
      if (animationFrameId.current !== null) {
        cancelAnimationFrame(animationFrameId.current);
        animationFrameId.current = null;
      }

      if (updateIntervalId.current !== null) {
        clearInterval(updateIntervalId.current);
        updateIntervalId.current = null;
      }
    };
  }, [currentTime, progressState.isDragging, isValidDuration, updateProgressMetrics]);

  // Error handler
  const handleError = useCallback((error: Error, context: string) => {
    console.warn(`Progress bar error (${context}):`, error);
    setHasError(true);
    onError?.(error);
  }, [onError]);

  // Time calculation from mouse event
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

  // Update progress state
  const updateProgressState = useCallback((updates: Partial<ProgressState>) => {
    startTransition(() => {
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

        // If tooltip state changed, update it directly
        if ('hoverTime' in updates || 'tooltipPosition' in updates) {
          updateTooltip(next.hoverTime, next.tooltipPosition);
        }

        return changed ? next : prev;
      });
    });
  }, [updateTooltip]);

  // Seek to time
  const seekToTime = useCallback((seekTime: number) => {
    try {
      const clampedTime = clamp(seekTime, 0, duration);
      // Set smoothed time directly
      currentMetrics.current.smoothedTime = clampedTime;
      // Update the player
      seekTo(clampedTime);
      // Update DOM immediately
      updateProgressMetrics();
    } catch (error) {
      handleError(error as Error, 'seekToTime');
    }
  }, [duration, seekTo, handleError, updateProgressMetrics]);

  // Mouse down handler
  const handleMouseDown = useCallback((event: React.MouseEvent<HTMLDivElement>) => {
    if (disabled || !isValidDuration) return;
    try {
      event.preventDefault();
      const time = calculateTimeFromEvent(event);
      updateProgressState({ isDragging: true, dragTime: time });
      // Apply immediately to DOM
      currentMetrics.current.displayProgress = (time / duration) * 100;
      updateProgressMetrics();
    } catch (error) {
      handleError(error as Error, 'handleMouseDown');
    }
  }, [disabled, isValidDuration, calculateTimeFromEvent, updateProgressState, handleError, duration, updateProgressMetrics]);

  // Mouse move handler
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

          // Update tooltip directly
          updateTooltip(payload.time, payload.pos);
          // Also update state so component knows tooltip is visible
          updateProgressState({
            hoverTime: payload.time,
            tooltipPosition: payload.pos,
          });
        });
      }
    } catch (error) {
      handleError(error as Error, 'handleContainerMouseMove');
    }
  }, [isValidDuration, calculateTimeFromEvent, duration, showTooltip, updateProgressState, handleError, updateTooltip]);

  // Global mouse move handler for dragging
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

          // Update state
          updateProgressState({ dragTime: t });

          // Apply directly to DOM for immediate feedback
          currentMetrics.current.displayProgress = (t / duration) * 100;
          updateProgressMetrics();
        });
      }
    } catch (error) {
      handleError(error as Error, 'handleGlobalMouseMove');
    }
  }, [progressState.isDragging, isValidDuration, calculateTimeFromEvent, updateProgressState, handleError, duration, updateProgressMetrics]);

  // Mouse up handler
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

  // Mouse leave handler
  const handleMouseLeave = useCallback(() => {
    updateProgressState({ hoverTime: null });
    // Also directly update DOM
    updateTooltip(null, 0);
  }, [updateProgressState, updateTooltip]);

  // Keyboard handler
  const handleKeyDown = useCallback((event: React.KeyboardEvent<HTMLDivElement>) => {
    if (disabled || !isValidDuration) return;
    try {
      const step = 5;
      let newTime = currentTime;
      switch (event.key) {
        case 'ArrowLeft':
          event.preventDefault();
          newTime = Math.max(0, currentTime - step);
          break;
        case 'ArrowRight':
          event.preventDefault();
          newTime = Math.min(duration, currentTime + step);
          break;
        case 'Home':
          event.preventDefault();
          newTime = 0;
          break;
        case 'End':
          event.preventDefault();
          newTime = duration;
          break;
        default:
          return;
      }
      seekToTime(newTime);
    } catch (error) {
      handleError(error as Error, 'handleKeyDown');
    }
  }, [disabled, isValidDuration, currentTime, duration, seekToTime, handleError]);

  // Click handler
  const handleClick = useCallback((event: React.MouseEvent<HTMLDivElement>) => {
    if (disabled || !isValidDuration || progressState.isDragging) return;
    try {
      const seekTime = calculateTimeFromEvent(event);
      seekToTime(seekTime);
    } catch (error) {
      handleError(error as Error, 'handleClick');
    }
  }, [disabled, isValidDuration, progressState.isDragging, calculateTimeFromEvent, seekToTime, handleError]);

  // Error retry handler
  const handleRetry = useCallback(() => {
    setHasError(false);
  }, []);

  // Event listeners setup
  useEffect(() => {
    if (!progressState.isDragging) return;

    document.addEventListener('mousemove', handleGlobalMouseMove, { passive: true });
    document.addEventListener('mouseup', handleMouseUp);

    return () => {
      if (dragRafId.current != null) {
        cancelAnimationFrame(dragRafId.current);
        dragRafId.current = null;
        pendingDragTime.current = null;
      }

      document.removeEventListener('mousemove', handleGlobalMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
    };
  }, [progressState.isDragging, handleGlobalMouseMove, handleMouseUp]);

  // Setup refs after initial render
  useEffect(() => {
    // Initial DOM update
    if (isValidDuration) {
      updateProgressMetrics();
    }
  }, [isValidDuration, updateProgressMetrics]);

  // Memoize container classes
  const containerClasses = useMemo(() => [
    styles.progressSliderContainer,
    disabled && styles.isDisabled,
    !isValidDuration && styles.isLoading,
    hasError && styles.hasError,
  ].filter(Boolean).join(' '), [disabled, isValidDuration, hasError]);

  // Memoize thumb classes
  const thumbClasses = useMemo(() => [
    styles.progressThumb,
    progressState.isDragging && styles.isDragging,
  ].filter(Boolean).join(' '), [progressState.isDragging]);

  // Format time values for aria labels
  const formattedCurrentTime = useMemo(() => formatTime(currentTime), [currentTime]);
  const formattedDuration = useMemo(() => formatTime(duration), [duration]);
  const ariaValueText = `${formattedCurrentTime} of ${formattedDuration}`;

  // Return error or loading states if needed
  if (hasError) return <ErrorState onRetry={handleRetry}/>;
  if (!isValidDuration) return <LoadingState/>;

  return (
    <Flex direction="column" width="100%">
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
        aria-label={`Progress: ${ariaValueText}`}
        aria-describedby={showTooltip ? tooltipId.current : undefined}
        aria-valuemin={0}
        aria-valuemax={duration}
        aria-valuenow={currentTime}
        aria-valuetext={ariaValueText}
        aria-orientation="horizontal"
        data-testid="progress-bar-container"
      >
        <div ref={progressTrackRef} className={styles.progressTrack}>
          <div ref={bufferedProgressRef} className={styles.bufferedProgress} />
          <div ref={currentProgressRef} className={styles.currentProgress} />
        </div>

        <div
          ref={thumbRef}
          className={thumbClasses}
          aria-hidden="true"
          style={{ transform: 'translateY(-50%)' }}
        />

        <div
          ref={tooltipRef}
          id={tooltipId.current}
          className={styles.timeTooltip}
          style={{ display: 'none' }}
          role="tooltip"
        />

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
    </Flex>
  );
});
