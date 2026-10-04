import React, { useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { useAudioPlayback } from '@/features/player/hooks/use-audio-playback'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { audioService } from '@/features/player/services/audio-service'

export interface MediaSnapshot {
  track: string | undefined
  index: number
  playing: boolean
  duration: number
  time: number
  active: number
  source: string | undefined
  outputGain: number | undefined
  elements: { id: string | null; paused: boolean; ended: boolean; time: number; volume: number; muted: boolean; loads: number }[]
  events: { element: number; type: string; time: number; ended: boolean; at: number }[]
}

const elements: HTMLAudioElement[] = []
const events: MediaSnapshot['events'] = []
const loads: number[] = []
const OriginalAudio = window.Audio
window.Audio = function (...args: ConstructorParameters<typeof Audio>) {
  const element = new OriginalAudio(...args)
  const index = elements.push(element) - 1
  loads[index] = 0
  for (const type of ['loadstart', 'playing', 'pause', 'ended', 'timeupdate']) {
    element.addEventListener(type, () => {
      if (type === 'loadstart') loads[index]++
      events.push({ element: index, type, time: element.currentTime, ended: element.ended, at: performance.now() })
    })
  }
  return element
} as unknown as typeof Audio

const tracks = ['first', 'second', 'third'].map(publicId => ({ publicId, title: publicId }))
let pendingResume: ((reason: Error) => void) | null = null
export function Fixture() {
  useAudioPlayback()
  useEffect(() => { document.documentElement.dataset.ready = 'true' }, [])
  return null
}
usePlayerStore.setState({ queue: [], currentTrack: null, currentIndex: -1, isPlaying: false, volume: 60, muted: false, repeat: 'off', shuffle: false })
createRoot(document.getElementById('root')!).render(React.createElement(Fixture))

const fixture = {
  renderFade,
  start(crossfade: boolean) {
    const state = usePlayerStore.getState()
    state.setCrossfadeEnabled(crossfade)
    state.setCrossfadeDuration(1)
    state.playTrack(tracks[0], tracks)
  },
  snapshot(): MediaSnapshot {
    const state = usePlayerStore.getState()
    const graph = audioService.getProcessor() as unknown as { gainNode: GainNode } | null
    return {
      track: state.currentTrack?.publicId, index: state.currentIndex, playing: state.isPlaying,
      duration: state.duration, time: state.currentTime, active: elements.indexOf(state.audioElement!),
      source: audioService.getProcessor()?.getActiveSource(), outputGain: graph?.gainNode.gain.value, events: [...events],
      elements: elements.map((element, index) => ({ id: element.src ? new URL(element.src).searchParams.get('id') : null,
        paused: element.paused, ended: element.ended, time: element.currentTime, volume: element.volume, muted: element.muted, loads: loads[index] })),
    }
  },
  seek(time: number) { usePlayerStore.getState().seekTo(time) },
  volume(value: number) { usePlayerStore.getState().setVolume(value) },
  mute(value: boolean) { usePlayerStore.getState().setMuted(value) },
  pause() { usePlayerStore.getState().setIsPlaying(false) },
  resume() { usePlayerStore.getState().setIsPlaying(true) },
  manual() { usePlayerStore.getState().playTrack(tracks[2]) },
  activity(): string[] { return [...((window as unknown as { playbackActivity?: string[] }).playbackActivity ?? [])] },
  repeatOne() { usePlayerStore.getState().setRepeat('one') },
  notify(type: 'play' | 'ended') { usePlayerStore.getState().audioElement?.dispatchEvent(new Event(type)) },
  next() { usePlayerStore.getState().playNext() },
  previous() { usePlayerStore.getState().playPrevious() },
  select(index: number) { usePlayerStore.getState().playTrack(tracks[index], tracks) },
  holdNextResume() {
    const originalResume = audioService.resumeContextIfNeeded.bind(audioService)
    audioService.resumeContextIfNeeded = () => {
      audioService.resumeContextIfNeeded = originalResume
      return new Promise<void>((_resolve, reject) => { pendingResume = reject })
    }
  },
  resumePending() { return pendingResume !== null },
  rejectHeldResume() {
    if (!pendingResume) throw new Error('No pending context resume')
    const pending = pendingResume
    pendingResume = null
    pending(new Error('Obsolete context resume rejected'))
  },
  domPause(index: number) { elements[index].pause() },
  domPlay(index: number) { return elements[index].play() },
}
Object.assign(window, { playbackFixture: fixture })
export type PlaybackFixture = typeof fixture

async function renderFade(cancel: boolean) {
  const { AudioProcessor } = await import('@/features/player/services/audio-processor')
  const rate = 48000
  const context = new OfflineAudioContext(2, rate * 4, rate)
  const original = window.AudioContext
  window.AudioContext = function () { return context } as unknown as typeof AudioContext
  let processor: InstanceType<typeof AudioProcessor>
  try { processor = new AudioProcessor() } finally { window.AudioContext = original }
  const graph = processor as unknown as { analyzerNode: AnalyserNode }
  for (const [channel, gain] of [[0, processor.getSourceGainA()], [1, processor.getSourceGainB()]] as const) {
    const source = context.createBufferSource()
    const buffer = context.createBuffer(2, rate * 4, rate)
    for (let i = 0; i < buffer.length; i++) buffer.getChannelData(channel)[i] = .2 * Math.sin(2 * Math.PI * 440 * i / rate)
    source.buffer = buffer
    source.connect(gain)
    gain.connect(graph.analyzerNode)
    source.start()
  }
  processor.rebuildChain(['masterGain'])
  await new Promise(resolve => setTimeout(resolve, 60))
  const begin = context.suspend(2)
  const stop = cancel ? context.suspend(2.5) : undefined
  const rendered = context.startRendering()
  await begin
  processor.crossfadeToInactive(1)
  await context.resume()
  if (stop) {
    await stop
    processor.cancelCrossfade()
    await context.resume()
  }
  const result = await rendered
  const gains = (time: number) => [0, 1].map(channel => {
    const values = result.getChannelData(channel).slice(Math.round(time * rate), Math.round((time + .02) * rate))
    return Math.sqrt(values.reduce((sum, value) => sum + value * value, 0) / values.length) / (.2 / Math.sqrt(2))
  })
  return { before: gains(1.8), start: gains(2.02), middle: gains(2.45), after: gains(3.3), cancelled: gains(2.7) }
}
