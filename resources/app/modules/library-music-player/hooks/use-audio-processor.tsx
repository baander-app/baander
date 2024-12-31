import { useCallback, useEffect, useRef } from 'react';
import { setBufferSize, setLeftChannel, setRightChannel } from '@/store/music/music-player-slice.ts';
import { useAppDispatch } from '@/store/hooks.ts';
import { useAudioPlayer } from '@/modules/library-music-player/providers/audio-player-provider.tsx';

export function useAudioProcessor() {
  const dispatch = useAppDispatch();
  const {
    audioRef,
    song,
  } = useAudioPlayer();

  const songTimeInSeconds = useRef(0);
  const interval = useRef<NodeJS.Timeout>();

  const audioContext = useRef<AudioContext>();
  const merger = useRef<ChannelMergerNode>();

  const bassBiquadFilter = useRef<BiquadFilterNode>();
  const middleBiquadFilter = useRef<BiquadFilterNode>();
  const trebleBiquadFilter = useRef<BiquadFilterNode>();

  const leftChannelGainNode = useRef<GainNode>();
  const rightChannelGainNode = useRef<GainNode>();

  const analyser = useRef<AnalyserNode>();
  const leftChannelAnalyser = useRef<AnalyserNode>();
  const rightChannelAnalyser = useRef<AnalyserNode>();

  const channelSplitter = useRef<ChannelSplitterNode>();

  const startAnalyserInterval = useCallback(() => {
    if (!analyser.current || !song) {
      return;
    }

    // create frequency buffer
    const bufferLength = analyser.current.frequencyBinCount;
    dispatch(setBufferSize(bufferLength));

    // create left and right data buffers
    const leftChannel = new Uint8Array(bufferLength);
    const rightChannel = new Uint8Array(bufferLength);

    interval.current = setInterval(() => {
      songTimeInSeconds.current = audioRef.current.duration;

      if (leftChannelAnalyser.current && rightChannelAnalyser.current) {
        leftChannelAnalyser.current.getByteFrequencyData(leftChannel);
        rightChannelAnalyser.current.getByteFrequencyData(rightChannel);

        const leftChannelReducedValue = leftChannel.reduce(
          (acc, curr) => acc + curr,
          0,
        );
        const rightChannelReducedValue = rightChannel.reduce(
          (acc, curr) => acc + curr,
          0,
        );

        const leftChannelAverageValue =
          leftChannelReducedValue / leftChannel.length;
        const rightChannelAverageValue =
          rightChannelReducedValue / rightChannel.length;

        const leftChannelValue = Math.floor(leftChannelAverageValue);
        const rightChannelValue = Math.floor(rightChannelAverageValue);

        dispatch(setLeftChannel(leftChannelValue));
        dispatch(setRightChannel(rightChannelValue));
      }
    }, 20);
  }, [song]);

  const stopAnalyserInterval = useCallback(() => {
    if (interval.current) {
      clearInterval(interval.current);
    }
  }, []);

  const resetTimeCounter = useCallback(() => {
    songTimeInSeconds.current = 0;
  }, []);

  const setupAudioGainNodesAndAnalyzers = () => {
    if (!audioContext.current || !audioRef.current) {
      return;
    }

    const source = audioContext.current.createMediaElementSource(audioRef.current);

    // BASS GAIN NODE
    bassBiquadFilter.current = audioContext.current.createBiquadFilter();
    bassBiquadFilter.current.type = 'lowshelf';
    source.connect(bassBiquadFilter.current);

    // MIDDLE GAIN NODE
    middleBiquadFilter.current = audioContext.current.createBiquadFilter();
    middleBiquadFilter.current.type = 'peaking';
    bassBiquadFilter.current.connect(middleBiquadFilter.current);

    // TREBLE GAIN NODE
    trebleBiquadFilter.current = audioContext.current.createBiquadFilter();
    trebleBiquadFilter.current.type = 'highshelf';
    middleBiquadFilter.current.connect(trebleBiquadFilter.current);

    // CREATE ANALYSER
    analyser.current = audioContext.current.createAnalyser();
    leftChannelAnalyser.current = audioContext.current.createAnalyser();
    rightChannelAnalyser.current = audioContext.current.createAnalyser();

    // FREQUENCY ANALYSER
    trebleBiquadFilter.current.connect(analyser.current);
    analyser.current.fftSize = 1024;

    // CHANNELS SPLITTER
    channelSplitter.current = audioContext.current.createChannelSplitter(2);
    trebleBiquadFilter.current.connect(channelSplitter.current);

    // BALANCE GAIN NODES
    leftChannelGainNode.current = audioContext.current.createGain();
    rightChannelGainNode.current = audioContext.current.createGain();

    // LEFT CHANNEL GAIN NODE CONNECT TO LEFT CHANNEL
    channelSplitter.current.connect(leftChannelGainNode.current, 0);

    // RIGHT CHANNEL GAIN NODE CONNECT TO RIGHT CHANNEL
    channelSplitter.current.connect(rightChannelGainNode.current, 1);

    if (leftChannelAnalyser.current && rightChannelAnalyser.current) {
      // LEFT CHANNEL ANALYSER CONNECT TO LEFT CHANNEL GAIN NODE
      leftChannelGainNode.current.connect(leftChannelAnalyser.current);
      leftChannelAnalyser.current.fftSize = 1024;

      // RIGHT CHANNEL ANALYSER CONNECT TO RIGHT CHANNEL GAIN NODE
      rightChannelGainNode.current.connect(rightChannelAnalyser.current);
      rightChannelAnalyser.current.fftSize = 1024;
    }

    // ----- MERGE TOGETHER AUDIO SPLIT SOURCES ------------
    merger.current = audioContext.current.createChannelMerger(2);
    leftChannelGainNode.current.connect(merger.current, 0, 0);
    rightChannelGainNode.current.connect(merger.current, 0, 1);
    merger.current.connect(audioContext.current.destination);
  };

  const setBalance = useCallback((balance: number) => {
    if (!leftChannelGainNode.current || !rightChannelGainNode.current) {
      return;
    }

    leftChannelGainNode.current.gain.value = 1 - balance / 100;
    rightChannelGainNode.current.gain.value = balance / 100;
  }, []);

  const setStereo = useCallback((isStereoEnabled: boolean) => {
    if (
      !merger.current ||
      !leftChannelGainNode.current ||
      !rightChannelGainNode.current ||
      !audioContext.current
    ) {
      return;
    }

    if (isStereoEnabled) {
      leftChannelGainNode.current.connect(merger.current, 0, 0);
      rightChannelGainNode.current.connect(merger.current, 0, 1);
      merger.current.connect(audioContext.current.destination);
    } else {
      leftChannelGainNode.current.connect(merger.current, 0, 0);
      rightChannelGainNode.current.connect(merger.current, 0, 0);
      merger.current.connect(audioContext.current.destination);
    }
  }, []);

  const setBass = useCallback((bass: number) => {
    if (bassBiquadFilter.current) {
      bassBiquadFilter.current.gain.value = bass;
    }
  }, []);

  const setMiddle = useCallback((middle: number) => {
    if (middleBiquadFilter.current) {
      middleBiquadFilter.current.gain.value = middle;
    }
  }, []);

  const setTreble = useCallback((treble: number) => {
    if (trebleBiquadFilter.current) {
      trebleBiquadFilter.current.gain.value = treble;
    }
  }, []);

  useEffect(() => {
    if (audioRef.current && song) {
      audioContext.current = new AudioContext();
      setupAudioGainNodesAndAnalyzers();
      startAnalyserInterval();
    } else {
      stopAnalyserInterval();
      resetTimeCounter();
      stopAnalyserInterval();
    }
  }, [song]);

  return {
    setBalance,
    setStereo,
    setBass,
    setMiddle,
    setTreble,
  };
}