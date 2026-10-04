import { createElement } from 'react'
import styled, { keyframes } from 'styled-components'
import { XCircle, Clock, CheckCircle, AlertCircle, Loader2 } from 'lucide-react'

const spin = keyframes`
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
`

const SpinningLoader = styled(Loader2)`
  animation: ${spin} 1s linear infinite;
`

const STATUS_ICONS = {
  pending: Clock,
  in_progress: Loader2,
  completed: CheckCircle,
  failed: AlertCircle,
  cancelled: XCircle,
} as const

export function getStatusIcon(status: string) {
  const Icon = STATUS_ICONS[status as keyof typeof STATUS_ICONS] || Clock
  const isSpinning = status === 'in_progress'
  if (isSpinning) return createElement(SpinningLoader, { size: 14 })
  return createElement(Icon, { size: 14 })
}
