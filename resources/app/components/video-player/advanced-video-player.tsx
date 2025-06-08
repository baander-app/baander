import React, { useState, useRef } from 'react';
import {
  Box,
  Flex,
  Text,
  IconButton,
  Dialog,
  Select,
  Slider,
  Badge,
  Card,
  Tooltip,
  Separator
} from '@radix-ui/themes';
import {
  PlayIcon,
  PauseIcon,
  SpeakerLoudIcon,
  GearIcon,
  Cross2Icon,
  VideoIcon
} from '@radix-ui/react-icons';
import VideoPlayer from './video-player';

interface VideoSource {
  url: string;
  type: 'hls' | 'dash' | 'mp4';
  label: string;
  quality?: string;
}

interface AdvancedVideoPlayerProps {
  sources: VideoSource[];
  poster?: string;
  autoPlay?: boolean;
  className?: string;
  onSourceChange?: (source: VideoSource) => void;
}

export const AdvancedVideoPlayer: React.FC<AdvancedVideoPlayerProps> = ({
                                                                          sources,
                                                                          poster,
                                                                          autoPlay = false,
                                                                          className = '',
                                                                          onSourceChange,
                                                                        }) => {
  const [selectedSource, setSelectedSource] = useState<VideoSource | null>(sources[0] || null);
  const [currentTime, setCurrentTime] = useState(0);
  const [duration, setDuration] = useState(0);
  const [isPlaying, setIsPlaying] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [playbackRate, setPlaybackRate] = useState(1);

  const videoPlayerRef = useRef<any>(null);

  const handleSourceChange = (sourceUrl: string) => {
    const source = sources.find(s => s.url === sourceUrl);
    if (source) {
      setSelectedSource(source);
      onSourceChange?.(source);
    }
  };

  const handlePlaybackRateChange = (rate: string) => {
    const rateValue = parseFloat(rate);
    setPlaybackRate(rateValue);
    if (videoPlayerRef.current?.videoRef?.current) {
      videoPlayerRef.current.videoRef.current.playbackRate = rateValue;
    }
  };

  const formatTime = (time: number) => {
    const hours = Math.floor(time / 3600);
    const minutes = Math.floor((time % 3600) / 60);
    const seconds = Math.floor(time % 60);

    if (hours > 0) {
      return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
  };

  const playbackRates = [
    { value: '0.25', label: '0.25x' },
    { value: '0.5', label: '0.5x' },
    { value: '0.75', label: '0.75x' },
    { value: '1', label: 'Normal' },
    { value: '1.25', label: '1.25x' },
    { value: '1.5', label: '1.5x' },
    { value: '2', label: '2x' },
  ];

  if (!selectedSource) {
    return (
      <Card className={className}>
        <Flex direction="column" align="center" justify="center" style={{ minHeight: '200px' }}>
          <VideoIcon width="32" height="32" />
          <Text size="4" weight="bold" mt="3" mb="2">
            No video sources available
          </Text>
          <Text size="2" color="gray">
            Please provide at least one video source
          </Text>
        </Flex>
      </Card>
    );
  }

  return (
    <Box className={className} style={{ position: 'relative', width: '100%' }}>
      {/* Main Video Player */}
      <VideoPlayer
        ref={videoPlayerRef}
        src={selectedSource.url}
        type={selectedSource.type}
        poster={poster}
        autoPlay={autoPlay}
        controls={false} // We'll use custom controls
        onTimeUpdate={setCurrentTime}
        onLoadedData={() => {
          if (videoPlayerRef.current?.videoRef?.current) {
            setDuration(videoPlayerRef.current.videoRef.current.duration || 0);
          }
        }}
        onPlay={() => setIsPlaying(true)}
        onPause={() => setIsPlaying(false)}
      />

      {/* Custom Controls Overlay */}
      <Box
        position="absolute"
        bottom="0"
        left="0"
        right="0"
        p="4"
        style={{
          background: 'linear-gradient(transparent, rgba(0, 0, 0, 0.9))',
          borderBottomLeftRadius: 'var(--radius-3)',
          borderBottomRightRadius: 'var(--radius-3)',
        }}
      >
        {/* Progress Bar */}
        <Box mb="3">
          <Slider
            value={[currentTime]}
            max={duration || 100}
            step={0.1}
            onValueChange={(value) => {
              if (videoPlayerRef.current?.videoRef?.current) {
                videoPlayerRef.current.videoRef.current.currentTime = value[0];
              }
            }}
            style={{ width: '100%' }}
          />
        </Box>

        {/* Control Buttons */}
        <Flex align="center" justify="between">
          <Flex align="center" gap="3">
            {/* Play/Pause */}
            <Tooltip content={isPlaying ? 'Pause (Space)' : 'Play (Space)'}>
              <IconButton
                variant="ghost"
                size="3"
                onClick={() => {
                  if (isPlaying) {
                    videoPlayerRef.current?.videoRef?.current?.pause();
                  } else {
                    videoPlayerRef.current?.videoRef?.current?.play();
                  }
                }}
                style={{ color: 'white' }}
              >
                {isPlaying ? <PauseIcon width="20" height="20" /> : <PlayIcon width="20" height="20" />}
              </IconButton>
            </Tooltip>

            {/* Volume Control */}
            <Flex align="center" gap="2">
              <IconButton
                variant="ghost"
                size="2"
                onClick={() => {
                  if (videoPlayerRef.current?.videoRef?.current) {
                    const video = videoPlayerRef.current.videoRef.current;
                    video.muted = !video.muted;
                  }
                }}
                style={{ color: 'white' }}
              >
                <SpeakerLoudIcon />
              </IconButton>

              <Box style={{ width: '80px' }}>
                <Slider
                  defaultValue={[1]}
                  max={1}
                  step={0.1}
                  onValueChange={(value) => {
                    if (videoPlayerRef.current?.videoRef?.current) {
                      videoPlayerRef.current.videoRef.current.volume = value[0];
                    }
                  }}
                  size="1"
                />
              </Box>
            </Flex>

            {/* Time Display */}
            <Text size="2" style={{ color: 'white', fontFamily: 'var(--font-mono)' }}>
              {formatTime(currentTime)} / {formatTime(duration)}
            </Text>

            {/* Current Source Badge */}
            <Badge variant="soft" size="1">
              {selectedSource.label}
              {selectedSource.quality && ` - ${selectedSource.quality}`}
            </Badge>
          </Flex>

          {/* Settings Button */}
          <Dialog.Root open={showSettings} onOpenChange={setShowSettings}>
            <Dialog.Trigger>
              <Tooltip content="Settings">
                <IconButton
                  variant="ghost"
                  size="2"
                  style={{ color: 'white' }}
                >
                  <GearIcon />
                </IconButton>
              </Tooltip>
            </Dialog.Trigger>

            <Dialog.Content maxWidth="400px">
              <Dialog.Title>Video Settings</Dialog.Title>

              <Flex direction="column" gap="4" mt="4">
                {/* Quality Selection */}
                {sources.length > 1 && (
                  <Box>
                    <Text size="2" weight="bold" mb="2">Quality</Text>
                    <Select.Root value={selectedSource.url} onValueChange={handleSourceChange}>
                      <Select.Trigger style={{ width: '100%' }}>
                        <Select.Label />
                      </Select.Trigger>
                      <Select.Content>
                        {sources.map((source, index) => (
                          <Select.Item key={index} value={source.url}>
                            <Flex align="center" justify="between" style={{ width: '100%' }}>
                              <Text>{source.label}</Text>
                              {source.quality && (
                                <Badge variant="soft" size="1" ml="2">
                                  {source.quality}
                                </Badge>
                              )}
                            </Flex>
                          </Select.Item>
                        ))}
                      </Select.Content>
                    </Select.Root>
                  </Box>
                )}

                <Separator />

                {/* Playback Speed */}
                <Box>
                  <Text size="2" weight="bold" mb="2">Playback Speed</Text>
                  <Select.Root value={playbackRate.toString()} onValueChange={handlePlaybackRateChange}>
                    <Select.Trigger style={{ width: '100%' }}>
                      <Select.Label />
                    </Select.Trigger>
                    <Select.Content>
                      {playbackRates.map((rate) => (
                        <Select.Item key={rate.value} value={rate.value}>
                          {rate.label}
                        </Select.Item>
                      ))}
                    </Select.Content>
                  </Select.Root>
                </Box>
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
        </Flex>
      </Box>
    </Box>
  );
};

export default AdvancedVideoPlayer;