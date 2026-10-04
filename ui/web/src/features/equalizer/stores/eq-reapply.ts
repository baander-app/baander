import { reapplyBandsState } from './eq-bands-store'
import { reapplyProcessingState } from './eq-processing-store'

/** Reapply current preferences after the processor starts or resumes. */
export function reapplyAllEqState() {
  reapplyBandsState()
  reapplyProcessingState()
}
