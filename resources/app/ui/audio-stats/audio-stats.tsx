import React, { useEffect, useState, RefObject, useRef } from 'react';
import * as Accordion from '@radix-ui/react-accordion';
import * as Separator from '@radix-ui/react-separator';

let context: AudioContext | null = null;
let source: MediaElementAudioSourceNode | null = null;

export function getAudioContext() {
  if (!context) context = new AudioContext();
  return context;
}

export function getOrCreateSource(audio: HTMLMediaElement) {
  if (!source) {
    source = getAudioContext().createMediaElementSource(audio);
  }
  return source;
}

interface AudioStatsViewerProps {
  audioRef: RefObject<HTMLAudioElement>;
}

export const AudioStats: React.FC<AudioStatsViewerProps> = ({ audioRef }) => {
  const [stats, setStats] = useState<any>({});
  const [analyserData, setAnalyserData] = useState<number[]>([]);
  const audioContext = getAudioContext();
  const sourceNode = getOrCreateSource(audioRef.current);
  const analyserRef = useRef<AnalyserNode | null>(null);

  useEffect(() => {
    const audio = audioRef.current;
    if (!audio) return;

    const updateStats = () => {
      setStats({
        currentTime: audio.currentTime,
        duration: audio.duration,
        paused: audio.paused,
        volume: audio.volume,
        playbackRate: audio.playbackRate,
        buffered: audio.buffered.length ? audio.buffered.end(0) : 0,
        loop: audio.loop,
        muted: audio.muted,
        ended: audio.ended,
        readyState: audio.readyState,
        networkState: audio.networkState,
      });
    };

    audio.addEventListener('timeupdate', updateStats);
    audio.addEventListener('play', updateStats);
    audio.addEventListener('pause', updateStats);
    audio.addEventListener('volumechange', updateStats);
    audio.addEventListener('ended', updateStats);

    updateStats();
    
    if (!analyserRef.current) {
      analyserRef.current = audioContext.createAnalyser();
      analyserRef.current.fftSize = 64;

      sourceNode.connect(analyserRef.current);
      analyserRef.current.connect(audioContext.destination);
    }


    const dataArray = new Uint8Array(analyserRef.current.frequencyBinCount);

    const updateAnalyser = () => {
      if (analyserRef.current) {
        analyserRef.current.getByteFrequencyData(dataArray);
        setAnalyserData([...dataArray]);
      }
      requestAnimationFrame(updateAnalyser);
    };

    updateAnalyser();

    return () => {
      audio.removeEventListener('timeupdate', updateStats);
      audio.removeEventListener('play', updateStats);
      audio.removeEventListener('pause', updateStats);
      audio.removeEventListener('volumechange', updateStats);
      audio.removeEventListener('ended', updateStats);
    };
  }, [audioRef]);

  return (
    <div style={{ padding: 16, borderRadius: 8, backgroundColor: 'var(--color-background)' }}>
      <h2 style={{ fontSize: 20, fontWeight: 'bold', marginBottom: 16 }}>Audio Stats Viewer</h2>

      <Accordion.Root type="multiple" style={{ width: '100%' }}>
        <Accordion.Item value="audio-stats">
          <Accordion.Header>
            <Accordion.Trigger style={{ textAlign: 'left', fontWeight: 600, padding: 8, cursor: 'pointer' }}>
              Audio Element Stats
            </Accordion.Trigger>
          </Accordion.Header>
          <Accordion.Content>
            <pre>
              {JSON.stringify(stats, null, 2)}
            </pre>
          </Accordion.Content>
        </Accordion.Item>

        <Separator.Root style={{ backgroundColor: '#ddd', height: 1, margin: '12px 0' }} />

        <Accordion.Item value="frequency-data">
          <Accordion.Header>
            <Accordion.Trigger style={{ textAlign: 'left', fontWeight: 600, padding: 8, cursor: 'pointer' }}>
              Frequency Data (Analyser)
            </Accordion.Trigger>
          </Accordion.Header>
          <Accordion.Content>
            <div style={{ marginTop: 24 }}>
              <h3 style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Live Frequency Line Graph</h3>
              <div
                style={{
                  position: 'relative',
                  width: '100%',
                  height: 100,
                  border: '1px solid #ddd',
                  background: '#fafafa',
                }}
              >
                {analyserData.map((value, i) => {
                  const x = (i / analyserData.length) * 100; // % from left
                  const y = 100 - (value / 255) * 100; // invert for top
                  return (
                    <div
                      key={i}
                      style={{
                        position: 'absolute',
                        left: `${x}%`,
                        bottom: 0,
                        width: 2,
                        height: 100 - y + 1,
                        backgroundColor: 'var(--accent-10)',
                      }}
                    />
                  );
                })}
              </div>
            </div>
          </Accordion.Content>
        </Accordion.Item>
      </Accordion.Root>
    </div>
  );
};