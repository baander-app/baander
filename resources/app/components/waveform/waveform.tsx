import { createRef, startTransition, useEffect, useState } from 'react';
import { Rnd } from 'react-rnd';
import WaveSurfer from 'wavesurfer.js';
import Hover from 'wavesurfer.js/dist/plugins/hover.esm.js'
import { useMusicSource } from '@/providers';
import { CloseButton, Loader, Text } from '@mantine/core';
import styles from './waveform.module.scss';

export interface WaveformProps {
  onClose: () => void;
}
export function Waveform({ onClose }: WaveformProps) {
  const musicSource = useMusicSource();
  const [waveSurfer, setWaveSurfer] = useState<WaveSurfer>();
  const waveSurferRef = createRef<HTMLDivElement>();
  const [isLoading, setIsLoading] = useState<boolean>(false);
  // Window state
  const [size, updateSize] = useState({ width: 800, height: 180 });
  const [position, updatePosition] = useState({ x: 400, y: -200 });


  useEffect(() => {
    if (waveSurferRef.current) {
      const instance = WaveSurfer.create({
        container: waveSurferRef.current,
        waveColor: 'rgb(200, 0, 200)',
        progressColor: 'rgb(100, 0, 100)',
        autoplay: true,
        normalize: true,
        backend: 'WebAudio',
        plugins: [
          Hover.create({
            lineColor: '#4981de',
            lineWidth: 2,
            labelBackground: '#555',
            labelColor: '#fff',
            labelSize: '11px',
          }),
        ],
      });

      setWaveSurfer(instance);
    }
  }, [waveSurferRef.current]);

  useEffect(() => {
    if (waveSurfer && musicSource.audioRef?.current) {
      waveSurfer.setMediaElement(musicSource.audioRef.current);
    }
  }, [waveSurfer, musicSource.audioRef?.current]);

  useEffect(() => {
    if (waveSurfer && musicSource.authenticatedSource) {
      setIsLoading(true);

      waveSurfer.empty();

      waveSurfer
        .load(musicSource.authenticatedSource)
        .then(() => {
          setIsLoading(false);
        });
    }
  }, [waveSurfer, musicSource.authenticatedSource]);

  useEffect(() => {
    return () => {
      waveSurfer?.destroy();
    };
  }, []);

  return (
    <Rnd
      size={size}
      position={position}
      enableResizing
      onDragStop={(e, d) => {
        updatePosition({ x: d.x, y: d.y });
      }}
      onResizeStop={(e, direction, ref, delta, position) => {
        updateSize({
          width: Number(ref.style.width),
          height: Number(ref.style.height),
        });
        updatePosition(position);
      }}
    >
      <div className={styles.container}>
        <div className={styles.titleBar}>
          <Text fw="bold">Waveform</Text>
          <CloseButton onClick={() => onClose()} />
        </div>

        {isLoading && <Loader color="indigo" type="dots"/>}

        <div>
          <div ref={waveSurferRef}></div>
        </div>
      </div>

    </Rnd>
  );
}