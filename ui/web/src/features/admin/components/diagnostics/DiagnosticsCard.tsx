import type { ReactNode } from 'react'
import { SectionCard } from '@/shared/components/section-card'

interface DiagnosticsCardProps {
  title: string
  action?: ReactNode
  children: ReactNode
}

/** A section card exposed as a named landmark region. */
export function DiagnosticsCard({ title, action, children }: DiagnosticsCardProps) {
  return (
    <section aria-label={title}>
      <SectionCard title={title} action={action}>
        {children}
      </SectionCard>
    </section>
  )
}
