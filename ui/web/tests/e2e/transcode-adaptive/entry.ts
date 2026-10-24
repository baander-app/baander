import { attachHlsPlayback } from '../../../src/features/catalog/hooks/hls-playback'

const video = document.querySelector<HTMLVideoElement>('#video')!
const originalVideo = video
const errors: string[] = []
const events: { name: string; at: number; time: number }[] = []
const frames: { at: number; time: number; width: number; height: number; phase: string }[] = []
interface AudioObservation {
  at: number
  time: number
  rms: number
  phase: string
  sampleRate: number
  dominantFrequency: number | null
  frequencyBinHz: number | null
  peakDecibels: number | null
}
const audio: AudioObservation[] = []
let phase = 'startup'
let attachments = 0
let readyCount = 0
let audioContext: AudioContext | undefined
let analyser: AnalyserNode | undefined
let frequencyBins: Float32Array<ArrayBuffer> | undefined
let phaseAudioSamples = 0
let phaseAudioPeak = 0
let firstPlayingTime: number | null = null
let mediaSource: string | null = null
let sourceChanges = 0

for (const name of ['loadedmetadata', 'playing', 'waiting', 'stalled', 'ended', 'error', 'seeking', 'seeked', 'emptied', 'ratechange']) {
  video.addEventListener(name, () => {
    events.push({ name, at: performance.now(), time: video.currentTime })
    if (events.length > 500) events.shift()
    if (name === 'playing' && firstPlayingTime === null) firstPlayingTime = video.currentTime
  })
}

function observeFrame(at: number, metadata: VideoFrameCallbackMetadata) {
  frames.push({ at, time: metadata.mediaTime, width: metadata.width, height: metadata.height, phase })
  if (frames.length > 500) frames.shift()
  video.requestVideoFrameCallback(observeFrame)
}
video.requestVideoFrameCallback(observeFrame)

setInterval(() => {
  if (video.currentSrc && video.currentSrc !== mediaSource) {
    mediaSource = video.currentSrc
    sourceChanges++
  }
  if (!analyser || video.paused || video.ended) return
  const samples = new Float32Array(analyser.fftSize)
  analyser.getFloatTimeDomainData(samples)
  const energy = samples.reduce((sum, sample) => sum + sample * sample, 0) / samples.length
  const rms = Math.sqrt(energy)
  phaseAudioPeak = Math.max(phaseAudioPeak, rms)
  const sampleRate = audioContext?.sampleRate ?? 0
  let dominantFrequency: number | null = null
  let frequencyBinHz: number | null = null
  let peakDecibels: number | null = null
  if (window.adaptiveFrequencyAnalysis && sampleRate > 0) {
    frequencyBins ??= new Float32Array(analyser.frequencyBinCount)
    analyser.getFloatFrequencyData(frequencyBins)
    let peak = -Infinity
    let peakBin = 0
    for (let bin = 1; bin < frequencyBins.length; bin++) {
      if (frequencyBins[bin] > peak) {
        peak = frequencyBins[bin]
        peakBin = bin
      }
    }
    frequencyBinHz = sampleRate / analyser.fftSize
    if (Number.isFinite(peak) && rms > 0.001) {
      dominantFrequency = peakBin * frequencyBinHz
      peakDecibels = peak
    }
  }
  audio.push({ at: performance.now(), time: video.currentTime, rms, phase, sampleRate,
    dominantFrequency, frequencyBinHz, peakDecibels })
  if (audio.length > 30) audio.shift()
  phaseAudioSamples++
}, 100)

document.querySelector('#play')!.addEventListener('click', async () => {
  try {
    audioContext = new AudioContext()
    analyser = audioContext.createAnalyser()
    analyser.fftSize = 2048
    const source = audioContext.createMediaElementSource(video)
    const silence = audioContext.createGain()
    silence.gain.value = 0
    source.connect(analyser)
    analyser.connect(silence)
    silence.connect(audioContext.destination)
    await audioContext.resume()
    attachments++
    attachHlsPlayback({
      video,
      url: window.adaptiveManifest,
      position: 0,
      onError: failure => errors.push(`Playback adapter failed: ${failure ?? 'media'}`),
      onReady: () => {
        readyCount++
        video.play().catch(error => errors.push(String(error)))
      },
    })
  } catch (error) {
    errors.push(String(error))
  }
}, { once: true })

function adaptivePhase(next: string) {
  phase = next
  phaseAudioPeak = 0
  phaseAudioSamples = 0
}

function adaptiveState() {
  return {
    at: performance.now(), timeOrigin: performance.timeOrigin, phase, time: video.currentTime,
    duration: Number.isFinite(video.duration) ? video.duration : null,
    width: video.videoWidth, height: video.videoHeight,
    decodedFrames: video.getVideoPlaybackQuality().totalVideoFrames,
    paused: video.paused, ended: video.ended, playbackRate: video.playbackRate,
    phaseAudioPeak, phaseAudioSamples, audioContextState: audioContext?.state ?? null, audioContextSampleRate: audioContext?.sampleRate ?? null,
    attachments, readyCount, sourceChanges, sameVideo: video === originalVideo && document.querySelector('#video') === originalVideo,
    firstPlayingTime, mediaError: video.error ? { code: video.error.code, message: video.error.message } : null,
    errors: [...errors], events: [...events], frames: [...frames], audio: [...audio],
  }
}

declare global {
  interface Window {
    adaptiveManifest: string
    adaptiveFrequencyAnalysis?: boolean
    adaptivePhase: typeof adaptivePhase
    adaptiveState: typeof adaptiveState
  }
}
window.adaptivePhase = adaptivePhase
window.adaptiveState = adaptiveState
