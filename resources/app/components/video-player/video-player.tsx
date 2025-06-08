import React, { useRef, useEffect, useState, useCallback } from 'react';
import {
  Box,
  Flex,
  Text,
  Card,
  Spinner,
  IconButton,
  Slider,
  Tooltip,
  Dialog,
  Button,
  ScrollArea,
  Badge,
  Separator,
  Select,
} from '@radix-ui/themes';
import {
  PlayIcon,
  PauseIcon,
  SpeakerLoudIcon,
  SpeakerOffIcon,
  FileTextIcon,
  Cross2Icon,
  DownloadIcon,
  TrashIcon,
  ChatBubbleIcon,
} from '@radix-ui/react-icons';
import Hls from 'hls.js';
import * as dashjs from 'dashjs';

interface LogEntry {
  timestamp: number;
  source: 'video' | 'hls' | 'dash' | 'player' | 'subtitles';
  level: 'info' | 'warn' | 'error' | 'debug';
  event: string;
  data?: any;
  message?: string;
}

interface SubtitleTrack {
  id: string;
  label: string;
  language: string;
  src?: string;
  kind?: 'subtitles' | 'captions' | 'descriptions' | 'chapters' | 'metadata';
  default?: boolean;
  srcLang?: string;
}

export interface VideoPlayerProps extends React.RefAttributes<HTMLVideoElement> {
  src: string;
  type?: 'hls' | 'dash' | 'mp4';
  autoPlay?: boolean;
  controls?: boolean;
  muted?: boolean;
  loop?: boolean;
  poster?: string;
  className?: string;
  debug?: boolean;
  subtitles?: SubtitleTrack[];
  onLoadStart?: () => void;
  onLoadedData?: () => void;
  onPlay?: () => void;
  onPause?: () => void;
  onEnded?: () => void;
  onError?: (error: any) => void;
  onTimeUpdate?: (currentTime: number) => void;
  onLogEntry?: (entry: LogEntry) => void;
  onSubtitleChange?: (track: SubtitleTrack | null) => void;
}

export const VideoPlayer: React.FC<VideoPlayerProps> = ({
                                                          src,
                                                          type,
                                                          autoPlay = false,
                                                          controls = true,
                                                          muted = false,
                                                          loop = false,
                                                          poster,
                                                          className = '',
                                                          debug = false,
                                                          subtitles = [],
                                                          onLoadStart,
                                                          onLoadedData,
                                                          onPlay,
                                                          onPause,
                                                          onEnded,
                                                          onError,
                                                          onTimeUpdate,
                                                          onLogEntry,
                                                          onSubtitleChange,
                                                        }) => {
  const videoRef = useRef<HTMLVideoElement>(null);
  const hlsRef = useRef<Hls | null>(null);
  const dashPlayerRef = useRef<dashjs.MediaPlayerClass | null>(null);

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [currentTime, setCurrentTime] = useState(0);
  const [duration, setDuration] = useState(0);
  const [isPlaying, setIsPlaying] = useState(false);
  const [isMuted, setIsMuted] = useState(muted);
  const [volume, setVolume] = useState(1);
  const [showControls, setShowControls] = useState(true);

  // Debug state
  const [logs, setLogs] = useState<LogEntry[]>([]);
  const [showDebugger, setShowDebugger] = useState(false);
  const [autoScroll, setAutoScroll] = useState(true);
  const scrollAreaRef = useRef<HTMLDivElement>(null);

  // Subtitle state
  const [availableSubtitles, setAvailableSubtitles] = useState<SubtitleTrack[]>([]);
  const [selectedSubtitle, setSelectedSubtitle] = useState<string | null>(null);
  const [showSubtitleSelector, setShowSubtitleSelector] = useState(false);

  // Logger function
  const addLog = useCallback((entry: Omit<LogEntry, 'timestamp'>) => {
    const logEntry: LogEntry = {
      ...entry,
      timestamp: Date.now(),
    };

    setLogs(prev => [...prev, logEntry]);
    onLogEntry?.(logEntry);

    // Also log to console in debug mode
    if (debug) {
      const logLevel = entry.level === 'info' ? 'log' : entry.level;
      console[logLevel](`[${entry.source.toUpperCase()}] ${entry.event}:`, entry.data || entry.message);
    }
  }, [debug, onLogEntry]);

  // Clear logs
  const clearLogs = useCallback(() => {
    setLogs([]);
  }, []);

  // Export logs
  const exportLogs = useCallback(() => {
    const logsJson = JSON.stringify(logs, null, 2);
    const blob = new Blob([logsJson], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `video-player-logs-${new Date().toISOString()}.json`;
    a.click();
    URL.revokeObjectURL(url);
  }, [logs]);

  // Auto scroll to bottom when new logs are added
  useEffect(() => {
    if (autoScroll && scrollAreaRef.current) {
      scrollAreaRef.current.scrollTop = scrollAreaRef.current.scrollHeight;
    }
  }, [logs, autoScroll]);

  // Initialize subtitles
  const initializeSubtitles = useCallback(() => {
    const video = videoRef.current;
    if (!video) return;

    // Clear existing tracks
    const existingTracks = Array.from(video.textTracks);
    existingTracks.forEach(track => {
      track.mode = 'disabled';
    });

    // Combine external subtitles with provided subtitles
    const allSubtitles = [...subtitles];

    // Add subtitles from HLS/DASH if available
    if (hlsRef.current) {
      const hlsSubtitles = hlsRef.current.subtitleTracks;
      hlsSubtitles.forEach((track, index) => {
        allSubtitles.push({
          id: `hls-${index}`,
          label: track.name || `Track ${index + 1}`,
          language: track.lang || 'unknown',
          kind: 'subtitles'
        });
      });
    }

    if (dashPlayerRef.current) {
      const dashSubtitles = dashPlayerRef.current.getTracksFor('text');
      dashSubtitles.forEach((track, index) => {
        allSubtitles.push({
          id: `dash-${index}`,
          label: track.lang || `Track ${index + 1}`,
          language: track.lang || 'unknown',
          kind: 'subtitles'
        });
      });
    }

    // Add HTML text tracks
    existingTracks.forEach((track, index) => {
      allSubtitles.push({
        id: `html-${index}`,
        label: track.label || `Track ${index + 1}`,
        language: track.language || 'unknown',
        kind: track.kind as any,
        srcLang: track.language
      });
    });

    setAvailableSubtitles(allSubtitles);

    addLog({
      source: 'subtitles',
      level: 'info',
      event: 'initialized',
      data: {
        total: allSubtitles.length,
        tracks: allSubtitles.map(t => ({ id: t.id, label: t.label, language: t.language }))
      }
    });

    // Set default subtitle if specified
    const defaultTrack = allSubtitles.find(track => track.default);
    if (defaultTrack) {
      setSelectedSubtitle(defaultTrack.id);
      handleSubtitleSelection(defaultTrack.id);
    }
  }, [subtitles, addLog]);

  // Handle subtitle selection
  const handleSubtitleSelection = useCallback((trackId: string | null) => {
    const video = videoRef.current;
    if (!video) return;

    addLog({
      source: 'subtitles',
      level: 'info',
      event: 'track_selected',
      data: { trackId }
    });

    // Disable all tracks first
    Array.from(video.textTracks).forEach(track => {
      track.mode = 'disabled';
    });

    if (!trackId || trackId === 'off') {
      setSelectedSubtitle(null);
      onSubtitleChange?.(null);

      // Disable HLS/DASH subtitles
      if (hlsRef.current) {
        hlsRef.current.subtitleTrack = -1;
      }
      if (dashPlayerRef.current) {
        dashPlayerRef.current.setTextTrack(-1);
      }
      return;
    }

    const track = availableSubtitles.find(t => t.id === trackId);
    if (!track) return;

    setSelectedSubtitle(trackId);
    onSubtitleChange?.(track);

    // Handle different track types
    if (trackId.startsWith('hls-') && hlsRef.current) {
      const hlsTrackIndex = parseInt(trackId.replace('hls-', ''));
      hlsRef.current.subtitleTrack = hlsTrackIndex;
    } else if (trackId.startsWith('dash-') && dashPlayerRef.current) {
      const dashTrackIndex = parseInt(trackId.replace('dash-', ''));
      dashPlayerRef.current.setTextTrack(dashTrackIndex);
    } else if (trackId.startsWith('html-')) {
      const htmlTrackIndex = parseInt(trackId.replace('html-', ''));
      const htmlTrack = video.textTracks[htmlTrackIndex];
      if (htmlTrack) {
        htmlTrack.mode = 'showing';
      }
    } else {
      // External subtitle track
      if (track.src) {
        // Add external subtitle track
        const trackElement = document.createElement('track');
        trackElement.kind = track.kind || 'subtitles';
        trackElement.src = track.src;
        trackElement.srclang = track.language;
        trackElement.label = track.label;
        trackElement.default = false;

        video.appendChild(trackElement);

        trackElement.addEventListener('load', () => {
          const textTrack = trackElement.track;
          textTrack.mode = 'showing';

          addLog({
            source: 'subtitles',
            level: 'info',
            event: 'external_track_loaded',
            data: { trackId, src: track.src }
          });
        });

        trackElement.addEventListener('error', () => {
          addLog({
            source: 'subtitles',
            level: 'error',
            event: 'external_track_error',
            data: { trackId, src: track.src }
          });
        });
      }
    }
  }, [availableSubtitles, addLog, onSubtitleChange]);

  // Determine video type from URL if not explicitly provided
  const getVideoType = useCallback((url: string): 'hls' | 'dash' | 'mp4' => {
    if (type) return type;

    if (url.includes('.m3u8') || url.includes('hls')) return 'hls';
    if (url.includes('.mpd') || url.includes('dash')) return 'dash';
    return 'mp4';
  }, [type]);

  // Cleanup function
  const cleanup = useCallback(() => {
    addLog({
      source: 'player',
      level: 'info',
      event: 'cleanup',
      message: 'Cleaning up player instances'
    });

    if (hlsRef.current) {
      hlsRef.current.destroy();
      hlsRef.current = null;
    }

    if (dashPlayerRef.current) {
      dashPlayerRef.current.reset();
      dashPlayerRef.current = null;
    }
  }, [addLog]);

  // Initialize HLS player
  const initializeHLS = useCallback(() => {
    if (!videoRef.current || !Hls.isSupported()) {
      addLog({
        source: 'hls',
        level: 'error',
        event: 'not_supported',
        message: 'HLS not supported'
      });
      return false;
    }

    cleanup();

    addLog({
      source: 'hls',
      level: 'info',
      event: 'initialize',
      data: { src }
    });

    const hls = new Hls({
      enableWorker: true,
      lowLatencyMode: true,
      backBufferLength: 90,
      debug: debug,
    });

    // HLS Event Logging
    Object.values(Hls.Events).forEach(event => {
      hls.on(event, (eventType, data) => {
        const level = event.includes('ERROR') ? 'error' :
                      event.includes('WARN') ? 'warn' : 'info';

        addLog({
          source: 'hls',
          level,
          event: eventType,
          data: data
        });
      });
    });

    hls.loadSource(src);
    hls.attachMedia(videoRef.current);

    hls.on(Hls.Events.MANIFEST_PARSED, () => {
      setIsLoading(false);
      onLoadedData?.();
      // Initialize subtitles after manifest is parsed
      setTimeout(initializeSubtitles, 100);
    });

    hls.on(Hls.Events.ERROR, (_event, data) => {
      console.error('HLS Error:', data);
      if (data.fatal) {
        setError(`HLS Error: ${data.details}`);
        onError?.(data);
      }
    });

    hlsRef.current = hls;
    return true;
  }, [src, cleanup, onLoadedData, onError, addLog, debug, initializeSubtitles]);

  // Initialize Dash player
  const initializeDash = useCallback(() => {
    if (!videoRef.current) {
      addLog({
        source: 'dash',
        level: 'error',
        event: 'no_video_element',
        message: 'No video element available'
      });
      return false;
    }

    cleanup();

    addLog({
      source: 'dash',
      level: 'info',
      event: 'initialize',
      data: { src }
    });

    const dashPlayer = dashjs.MediaPlayer().create();

    // Dash Event Logging
    Object.values(dashjs.MediaPlayer.events).forEach(event => {
      dashPlayer.on(event, (data: any) => {
        const level = event.includes('ERROR') ? 'error' :
                      event.includes('WARN') ? 'warn' : 'info';

        addLog({
          source: 'dash',
          level,
          event: event,
          data: data
        });
      });
    });

    dashPlayer.initialize(videoRef.current, src, autoPlay);

    dashPlayer.on(dashjs.MediaPlayer.events.STREAM_INITIALIZED, () => {
      setIsLoading(false);
      onLoadedData?.();
      // Initialize subtitles after stream is initialized
      setTimeout(initializeSubtitles, 100);
    });

    dashPlayer.on(dashjs.MediaPlayer.events.ERROR, (e: Error) => {
      console.error('Dash Error:', e);
      setError(`Dash Error: ${e.message || e.toString() || 'Unknown error'}`);
      onError?.(e);
    });

    dashPlayerRef.current = dashPlayer;
    return true;
  }, [src, autoPlay, cleanup, onLoadedData, onError, addLog, initializeSubtitles]);

  // Initialize native video
  const initializeNative = useCallback(() => {
    if (!videoRef.current) {
      addLog({
        source: 'video',
        level: 'error',
        event: 'no_video_element',
        message: 'No video element available'
      });
      return false;
    }

    cleanup();

    addLog({
      source: 'video',
      level: 'info',
      event: 'initialize_native',
      data: { src }
    });

    videoRef.current.src = src;
    return true;
  }, [src, cleanup, addLog]);

  // Add external subtitles as track elements
  useEffect(() => {
    const video = videoRef.current;
    if (!video || subtitles.length === 0) return;

    // Remove existing external tracks
    const existingTracks = video.querySelectorAll('track[data-external="true"]');
    existingTracks.forEach(track => track.remove());

    // Add external subtitle tracks
    subtitles.forEach((subtitle, index) => {
      if (subtitle.src) {
        const trackElement = document.createElement('track');
        trackElement.kind = subtitle.kind || 'subtitles';
        trackElement.src = subtitle.src;
        trackElement.srclang = subtitle.language;
        trackElement.label = subtitle.label;
        trackElement.default = subtitle.default || false;
        trackElement.setAttribute('data-external', 'true');
        trackElement.setAttribute('data-track-id', subtitle.id);

        video.appendChild(trackElement);
      }
    });

    // Initialize subtitles after adding tracks
    setTimeout(initializeSubtitles, 100);
  }, [subtitles, initializeSubtitles]);

  // Initialize player based on video type
  useEffect(() => {
    if (!src || !videoRef.current) return;

    setIsLoading(true);
    setError(null);
    onLoadStart?.();

    const videoType = getVideoType(src);
    let initialized = false;

    addLog({
      source: 'player',
      level: 'info',
      event: 'initialize',
      data: { src, type: videoType }
    });

    switch (videoType) {
      case 'hls':
        if (Hls.isSupported()) {
          initialized = initializeHLS();
        } else if (videoRef.current.canPlayType('application/vnd.apple.mpegurl')) {
          initialized = initializeNative();
        }
        break;

      case 'dash':
        initialized = initializeDash();
        break;

      default:
        initialized = initializeNative();
        break;
    }

    if (!initialized) {
      const errorMsg = 'Unsupported video format or browser';
      setError(errorMsg);
      setIsLoading(false);
      addLog({
        source: 'player',
        level: 'error',
        event: 'initialization_failed',
        message: errorMsg
      });
    }

    return cleanup;
  }, [src, getVideoType, initializeHLS, initializeDash, initializeNative, cleanup, onLoadStart, addLog]);

  // Video event handlers with logging
  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;

    const videoEvents = [
      'loadstart', 'progress', 'suspend', 'abort', 'error', 'emptied', 'stalled',
      'loadedmetadata', 'loadeddata', 'canplay', 'canplaythrough', 'playing',
      'waiting', 'seeking', 'seeked', 'ended', 'durationchange', 'timeupdate',
      'play', 'pause', 'ratechange', 'resize', 'volumechange'
    ];

    const eventHandlers: { [key: string]: (e: Event) => void } = {};

    videoEvents.forEach(eventName => {
      const handler = (e: Event) => {
        addLog({
          source: 'video',
          level: eventName === 'error' ? 'error' : 'debug',
          event: eventName,
          data: {
            currentTime: video.currentTime,
            duration: video.duration,
            readyState: video.readyState,
            networkState: video.networkState,
            buffered: video.buffered.length > 0 ? {
              start: video.buffered.start(0),
              end: video.buffered.end(video.buffered.length - 1)
            } : null
          }
        });
      };
      eventHandlers[eventName] = handler;
      video.addEventListener(eventName, handler);
    });

    const handleTimeUpdate = () => {
      const time = video.currentTime;
      setCurrentTime(time);
      onTimeUpdate?.(time);
    };

    const handleLoadedMetadata = () => {
      setDuration(video.duration);
      setVolume(video.volume);
      setIsMuted(video.muted);
      // Initialize subtitles when metadata is loaded for native videos
      if (!hlsRef.current && !dashPlayerRef.current) {
        setTimeout(initializeSubtitles, 100);
      }
    };

    const handlePlay = () => {
      setIsPlaying(true);
      onPlay?.();
    };

    const handlePause = () => {
      setIsPlaying(false);
      onPause?.();
    };

    const handleEnded = () => {
      setIsPlaying(false);
      onEnded?.();
    };

    const handleError = () => {
      const error = video.error;
      if (error) {
        setError(`Video Error: ${error.message}`);
        onError?.(error);
      }
    };

    const handleVolumeChange = () => {
      setVolume(video.volume);
      setIsMuted(video.muted);
    };

    video.addEventListener('timeupdate', handleTimeUpdate);
    video.addEventListener('loadedmetadata', handleLoadedMetadata);
    video.addEventListener('play', handlePlay);
    video.addEventListener('pause', handlePause);
    video.addEventListener('ended', handleEnded);
    video.addEventListener('error', handleError);
    video.addEventListener('volumechange', handleVolumeChange);

    return () => {
      videoEvents.forEach(eventName => {
        video.removeEventListener(eventName, eventHandlers[eventName]);
      });
      video.removeEventListener('timeupdate', handleTimeUpdate);
      video.removeEventListener('loadedmetadata', handleLoadedMetadata);
      video.removeEventListener('play', handlePlay);
      video.removeEventListener('pause', handlePause);
      video.removeEventListener('ended', handleEnded);
      video.removeEventListener('error', handleError);
      video.removeEventListener('volumechange', handleVolumeChange);
    };
  }, [onTimeUpdate, onPlay, onPause, onEnded, onError, addLog, initializeSubtitles]);

  // Control functions
  const togglePlay = () => {
    if (videoRef.current) {
      if (isPlaying) {
        videoRef.current.pause();
      } else {
        videoRef.current.play();
      }
    }
  };

  const toggleMute = () => {
    if (videoRef.current) {
      videoRef.current.muted = !isMuted;
    }
  };

  const handleSeek = (value: number[]) => {
    if (videoRef.current) {
      addLog({
        source: 'player',
        level: 'info',
        event: 'seek',
        data: { from: currentTime, to: value[0] }
      });
      videoRef.current.currentTime = value[0];
    }
  };

  const handleVolumeChange = (value: number[]) => {
    if (videoRef.current) {
      addLog({
        source: 'player',
        level: 'info',
        event: 'volume_change',
        data: { from: volume, to: value[0] }
      });
      videoRef.current.volume = value[0];
    }
  };

  const formatTime = (time: number) => {
    const minutes = Math.floor(time / 60);
    const seconds = Math.floor(time % 60);
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
  };

  const formatTimestamp = (timestamp: number) => {
    return new Date(timestamp).toLocaleTimeString();
  };

  const getLevelColor = (level: LogEntry['level']) => {
    switch (level) {
      case 'error': return 'red';
      case 'warn': return 'yellow';
      case 'info': return 'blue';
      case 'debug': return 'gray';
      default: return 'gray';
    }
  };

  if (error) {
    return (
      <Card className={className}>
        <Flex direction="column" align="center" justify="center" style={{ minHeight: '200px' }}>
          <Text size="4" weight="bold" color="red" mb="2">
            Video playback error
          </Text>
          <Text size="2" color="gray">
            {error}
          </Text>
        </Flex>
      </Card>
    );
  }

  return (
    <>
      <Box
        className={className}
        style={{
          position: 'relative',
          width: '100%',
          height: '100%',
          backgroundColor: 'var(--color-panel-solid)',
          borderRadius: 'var(--radius-3)'
        }}
        onMouseEnter={() => setShowControls(true)}
        onMouseLeave={() => setShowControls(false)}
      >
        {isLoading && (
          <Flex
            position="absolute"
            inset="0"
            align="center"
            justify="center"
            style={{
              backgroundColor: 'rgba(0, 0, 0, 0.8)',
              zIndex: 10,
              borderRadius: 'var(--radius-3)'
            }}
          >
            <Flex direction="column" align="center" gap="3">
              <Spinner size="3" />
              <Text size="2" style={{ color: 'white' }}>Loading video...</Text>
            </Flex>
          </Flex>
        )}

        <video
          ref={videoRef}
          autoPlay={autoPlay}
          muted={muted}
          loop={loop}
          poster={poster}
          crossOrigin="anonymous"
          style={{
            width: '100%',
            height: '100%',
            borderRadius: 'var(--radius-3)'
          }}
          playsInline
          onClick={togglePlay}
        >
          {subtitles.map((subtitle) => (
            subtitle.src && (
              <track
                key={subtitle.id}
                kind={subtitle.kind || 'subtitles'}
                src={subtitle.src}
                srcLang={subtitle.language}
                label={subtitle.label}
                default={subtitle.default}
              />
            )
          ))}
        </video>

        {controls && showControls && (
          <Box
            position="absolute"
            bottom="0"
            left="0"
            right="0"
            p="3"
            style={{
              background: 'linear-gradient(transparent, rgba(0, 0, 0, 0.8))',
              borderBottomLeftRadius: 'var(--radius-3)',
              borderBottomRightRadius: 'var(--radius-3)',
            }}
          >
            {/* Progress bar */}
            <Box mb="3">
              <Slider
                value={[currentTime]}
                max={duration || 100}
                step={0.1}
                onValueChange={handleSeek}
                style={{ width: '100%' }}
              />
            </Box>

            {/* Controls */}
            <Flex align="center" justify="between">
              <Flex align="center" gap="3">
                <Tooltip content={isPlaying ? 'Pause' : 'Play'}>
                  <IconButton
                    variant="ghost"
                    size="2"
                    onClick={togglePlay}
                    style={{ color: 'white' }}
                  >
                    {isPlaying ? <PauseIcon /> : <PlayIcon />}
                  </IconButton>
                </Tooltip>

                <Flex align="center" gap="2">
                  <Tooltip content={isMuted ? 'Unmute' : 'Mute'}>
                    <IconButton
                      variant="ghost"
                      size="1"
                      onClick={toggleMute}
                      style={{ color: 'white' }}
                    >
                      {isMuted ? <SpeakerOffIcon /> : <SpeakerLoudIcon />}
                    </IconButton>
                  </Tooltip>

                  <Box style={{ width: '80px' }}>
                    <Slider
                      value={[isMuted ? 0 : volume]}
                      max={1}
                      step={0.1}
                      onValueChange={handleVolumeChange}
                      size="1"
                    />
                  </Box>
                </Flex>

                <Text size="1" style={{ color: 'white', fontFamily: 'var(--font-mono)' }}>
                  {formatTime(currentTime)} / {formatTime(duration)}
                </Text>
              </Flex>

              <Flex align="center" gap="2">
                {/* Subtitle Selector */}
                {availableSubtitles.length > 0 && (
                  <Dialog.Root open={showSubtitleSelector} onOpenChange={setShowSubtitleSelector}>
                    <Dialog.Trigger>
                      <Tooltip content="Subtitles">
                        <IconButton
                          variant="ghost"
                          size="2"
                          style={{
                            color: selectedSubtitle ? 'var(--accent-9)' : 'white'
                          }}
                        >
                          <ChatBubbleIcon />
                          {selectedSubtitle && (
                            <Badge
                              variant="solid"
                              size="1"
                              style={{
                                position: 'absolute',
                                top: '-4px',
                                right: '-4px',
                                width: '8px',
                                height: '8px',
                                minWidth: 'unset',
                                padding: '0'
                              }}
                            />
                          )}
                        </IconButton>
                      </Tooltip>
                    </Dialog.Trigger>

                    <Dialog.Content style={{ maxWidth: '350px' }}>
                      <Dialog.Title>Subtitle Tracks</Dialog.Title>

                      <Flex direction="column" gap="3" mt="4">
                        <Select.Root
                          value={selectedSubtitle || 'off'}
                          onValueChange={handleSubtitleSelection}
                        >
                          <Select.Trigger style={{ width: '100%' }}>
                            <Text>Select subtitle track</Text>
                          </Select.Trigger>
                          <Select.Content>
                            <Select.Item value="off">
                              <Flex align="center" gap="2">
                                <Text>Off</Text>
                              </Flex>
                            </Select.Item>
                            {availableSubtitles.map((track) => (
                              <Select.Item key={track.id} value={track.id}>
                                <Flex align="center" justify="between" style={{ width: '100%' }}>
                                  <Flex direction="column" align="start">
                                    <Text>{track.label}</Text>
                                    <Text size="1" color="gray">
                                      {track.language} • {track.kind || 'subtitles'}
                                    </Text>
                                  </Flex>
                                  {track.default && (
                                    <Badge variant="soft" size="1">
                                      Default
                                    </Badge>
                                  )}
                                </Flex>
                              </Select.Item>
                            ))}
                          </Select.Content>
                        </Select.Root>

                        {selectedSubtitle && (
                          <Box p="2" style={{ backgroundColor: 'var(--color-panel-solid)', borderRadius: 'var(--radius-2)' }}>
                            <Text size="2" weight="bold" mb="1">Active Track:</Text>
                            {(() => {
                              const track = availableSubtitles.find(t => t.id === selectedSubtitle);
                              return track ? (
                                <Flex direction="column" gap="1">
                                  <Text size="1">{track.label}</Text>
                                  <Text size="1" color="gray">
                                    Language: {track.language} • Type: {track.kind || 'subtitles'}
                                  </Text>
                                </Flex>
                              ) : null;
                            })()}
                          </Box>
                        )}
                      </Flex>

                      <Dialog.Close>
                        <IconButton
                          style={{ position: 'absolute', top: '10px', right: '10px' }}
                          variant="ghost"
                          size="1"
                        >
                          <Cross2Icon />
                        </IconButton>
                      </Dialog.Close>
                    </Dialog.Content>
                  </Dialog.Root>
                )}

                {/* Debug Button */}
                {debug && (
                  <Tooltip content="Debug Logger">
                    <IconButton
                      variant="ghost"
                      size="2"
                      onClick={() => setShowDebugger(true)}
                      style={{ color: 'white' }}
                    >
                      <FileTextIcon />
                      {logs.length > 0 && (
                        <Badge
                          variant="solid"
                          size="1"
                          style={{
                            position: 'absolute',
                            top: '-8px',
                            right: '-8px',
                            minWidth: '16px',
                            height: '16px',
                            fontSize: '10px'
                          }}
                        >
                          {logs.length}
                        </Badge>
                      )}
                    </IconButton>
                  </Tooltip>
                )}
              </Flex>
            </Flex>
          </Box>
        )}
      </Box>

      {/* Debug Logger Dialog */}
      <Dialog.Root open={showDebugger} onOpenChange={setShowDebugger}>
        <Dialog.Content style={{ maxWidth: '800px', maxHeight: '600px' }}>
          <Dialog.Title>Video Player Debug Logger</Dialog.Title>

          <Flex direction="column" gap="3" mt="4" style={{ height: '500px' }}>
            {/* Controls */}
            <Flex align="center" justify="between">
              <Flex align="center" gap="2">
                <Text size="2" weight="bold">Logs: {logs.length}</Text>
                <Badge variant="soft" size="1">
                  Auto-scroll: {autoScroll ? 'ON' : 'OFF'}
                </Badge>
              </Flex>

              <Flex gap="2">
                <Button
                  variant="outline"
                  size="1"
                  onClick={() => setAutoScroll(!autoScroll)}
                >
                  Toggle Auto-scroll
                </Button>
                <Button
                  variant="outline"
                  size="1"
                  onClick={exportLogs}
                  disabled={logs.length === 0}
                >
                  <DownloadIcon width="12" height="12" />
                  Export
                </Button>
                <Button
                  variant="outline"
                  size="1"
                  color="red"
                  onClick={clearLogs}
                  disabled={logs.length === 0}
                >
                  <TrashIcon width="12" height="12" />
                  Clear
                </Button>
              </Flex>
            </Flex>

            <Separator />

            {/* Logs */}
            <ScrollArea style={{ height: '100%' }} ref={scrollAreaRef}>
              <Flex direction="column" gap="1">
                {logs.map((log, index) => (
                  <Card key={index} variant="surface">
                    <Flex direction="column" gap="1">
                      <Flex align="center" justify="between">
                        <Flex align="center" gap="2">
                          <Badge color={getLevelColor(log.level)} variant="soft" size="1">
                            {log.level.toUpperCase()}
                          </Badge>
                          <Badge variant="outline" size="1">
                            {log.source.toUpperCase()}
                          </Badge>
                          <Text size="1" weight="bold">
                            {log.event}
                          </Text>
                        </Flex>
                        <Text size="1" color="gray">
                          {formatTimestamp(log.timestamp)}
                        </Text>
                      </Flex>

                      {log.message && (
                        <Text size="1" color="gray">
                          {log.message}
                        </Text>
                      )}

                      {log.data && (
                        <Box
                          p="2"
                          style={{
                            backgroundColor: 'var(--color-panel-solid)',
                            borderRadius: 'var(--radius-2)',
                            fontFamily: 'var(--font-mono)',
                            fontSize: '11px',
                            overflow: 'auto'
                          }}
                        >
                          <pre>{JSON.stringify(log.data, null, 2)}</pre>
                        </Box>
                      )}
                    </Flex>
                  </Card>
                ))}

                {logs.length === 0 && (
                  <Flex align="center" justify="center" style={{ height: '200px' }}>
                    <Text color="gray">No logs yet. Start playing video to see events.</Text>
                  </Flex>
                )}
              </Flex>
            </ScrollArea>
          </Flex>

          <Dialog.Close>
            <IconButton
              style={{ position: 'absolute', top: '10px', right: '10px' }}
              variant="ghost"
              size="1"
            >
              <Cross2Icon />
            </IconButton>
          </Dialog.Close>
        </Dialog.Content>
      </Dialog.Root>
    </>
  );
};

export default VideoPlayer;