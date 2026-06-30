import { audioService } from '@/features/player/services/audio-service'
import { useEqBandsStore, flatBands } from './eq-bands-store'
import { useEqProcessingStore, CROSSFEED_PRESETS } from './eq-processing-store'

/**
 * Reapply all EQ-related state to the audio processor.
 * Called after processor init, preference sync, or profile switch.
 */
export function reapplyAllEqState() {
  const bandsState = useEqBandsStore.getState()
  const processingState = useEqProcessingStore.getState()
  const processor = audioService.getProcessor()
  if (!processor) return

  // Bands + Q
  const bandsToApply = bandsState.enabled
    ? bandsState.bands
    : flatBands().map((b) => ({ ...b, gain: 0 }))
  processor.updateEQBands(bandsToApply)

  // Compressor
  processor.setCompression(processingState.compressionEnabled)
  if (processingState.compressionEnabled) {
    processor.setCompressorParams({
      threshold: processingState.compressorThreshold,
      ratio: processingState.compressorRatio,
      knee: processingState.compressorKnee,
      attack: processingState.compressorAttack,
      release: processingState.compressorRelease,
    })
  }

  // Master gain
  processor.setMasterGain(processingState.masterGain)

  // Stereo width
  processor.setStereoWidth(processingState.stereoEnabled ? processingState.stereoWidth : 1)

  // Crossfeed
  processor.setCrossfeed(
    processingState.crossfeedEnabled ? CROSSFEED_PRESETS[processingState.crossfeedPreset] : 0,
  )

  // Loudness contour
  processor.setLoudnessContour(processingState.loudnessContourEnabled)

  // Normalization off on reapply (LUFS worklet will re-measure)
  if (!processingState.normalizationEnabled) {
    processor.applyVolumeNormalization(0, 0)
  }
}
