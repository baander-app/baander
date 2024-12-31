import { createRef, useEffect, useState } from 'react';
import { Rnd } from 'react-rnd';
import WaveSurfer from 'wavesurfer.js';
import { useMusicSource } from '@/providers';
import { Text } from '@mantine/core';
import styles from './waveform.module.scss';

export function Waveform() {
  const musicSource = useMusicSource();
  const [waveSurfer, setWaveSurfer] = useState<WaveSurfer>();
  const waveSurferRef = createRef<HTMLDivElement>();
  // Window state
  const [size, updateSize] = useState({ width: 500, height: 180 });
  const [position, updatePosition] = useState({ x: 400, y: -200 });


  useEffect(() => {
    if (waveSurferRef.current) {
      const instance = WaveSurfer.create({
        container: waveSurferRef.current,
        waveColor: '#4F4A85',
        progressColor: '#383351',
      });

      setWaveSurfer(instance);
    }
  }, [waveSurferRef.current]);

  useEffect(() => {
    if (!waveSurfer) {
      return;
    }

    if (musicSource.audioRef?.current) {
      waveSurfer.setMediaElement(musicSource.audioRef.current);
    }

    if (musicSource.authenticatedSource) {
      waveSurfer.load(musicSource.authenticatedSource);
    }
  }, [waveSurfer, musicSource.audioRef?.current]);

  useEffect(() => {
    return () => {
      waveSurfer?.destroy();
    };
  }, []);

  return (
    <Rnd
      size={size}
      position={position}
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
        </div>

        <div>
          <div ref={waveSurferRef}></div>
        </div>
      </div>

    </Rnd>
  );
}