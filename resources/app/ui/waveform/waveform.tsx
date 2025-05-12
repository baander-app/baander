import { createRef, useEffect, useState } from 'react';
import { Rnd } from 'react-rnd';
import WaveSurfer from 'wavesurfer.js';
// @ts-ignore
import Hover from 'wavesurfer.js/dist/plugins/hover.esm.js'
import { useMusicSource } from '@/providers/music-source-provider.tsx';
import styles from './waveform.module.scss';
import { CloseButton }   from '../buttons/close-button';
import { Spinner, Text } from '@radix-ui/themes';

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
  const [position, updatePosition] = useState({ x: -500, y: -200 });


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
      // @ts-ignore
      onDragStop={(e, d) => {
        updatePosition({ x: d.x, y: d.y });
      }}
      // @ts-ignore
      onResizeStop={(e, direction, ref, delta, position) => {
        updateSize({
          width: Number(ref.style.width),
          height: Number(ref.style.height),
        });
        updatePosition(position);
      }}
      className={styles.dnd}
    >
      <div className={styles.container}>
        <div className={styles.titleBar}>
          <Text>Waveform</Text>
          <CloseButton onClick={() => onClose()} />
        </div>

        {isLoading && <Spinner />}

        <div>
          <div ref={waveSurferRef}></div>
        </div>
      </div>

    </Rnd>
  );
}