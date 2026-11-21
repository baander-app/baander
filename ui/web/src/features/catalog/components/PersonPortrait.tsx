import { UserRound } from 'lucide-react'
import styled from 'styled-components'
import { useImageBlob } from '@/shared/hooks/use-image-blob'

const Portrait = styled.div`
  width: 100%;
  aspect-ratio: 2 / 3;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: var(--color-secondary);
  color: var(--color-muted-foreground);
  border-radius: var(--radius-md);
  img { width: 100%; height: 100%; object-fit: cover; }
`
interface PersonPortraitProps {
  url: string | null
  className?: string
}

export function PersonPortrait({ url, className }: PersonPortraitProps) {
  const localUrl = url?.startsWith('/api/images/') ? url : null
  const { src: localSource } = useImageBlob(localUrl)
  const src = localUrl ? localSource : url

  return (
    <Portrait className={className}>
      {src ? <img src={src} alt="" loading="lazy" /> : <UserRound size={48} aria-hidden="true" />}
    </Portrait>
  )
}
