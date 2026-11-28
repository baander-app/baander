import { useEffect, useRef, useState } from 'react'
import { Film } from 'lucide-react'
import styled from 'styled-components'
import { useImageBlob } from '@/shared/hooks/use-image-blob'

const Artwork = styled.span<{ $wide: boolean }>`
  display: grid;
  place-items: center;
  aspect-ratio: ${({ $wide }) => $wide ? '16 / 9' : '2 / 3'};
  overflow: hidden;
  background: var(--color-muted);
  color: var(--color-muted-foreground);
  border-radius: var(--radius-lg);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
`

interface VideoArtworkProps {
  source?: string | null
  wide?: boolean
  className?: string
}

export function VideoArtwork({ source, wide = false, className }: VideoArtworkProps) {
  const element = useRef<HTMLSpanElement>(null)
  const [visibleSource, setVisibleSource] = useState<string | null>(null)
  const managedSource = source?.startsWith('/api/') ? source : null

  useEffect(() => {
    const target = element.current
    if (!target || !managedSource || typeof IntersectionObserver === 'undefined') return
    let active = true
    const observer = new IntersectionObserver(entries => {
      if (active && entries.some(entry => entry.target === target && entry.isIntersecting
        && entry.boundingClientRect.width > 0 && entry.boundingClientRect.height > 0)) {
        active = false
        setVisibleSource(managedSource)
        observer.disconnect()
      }
    }, { rootMargin: '200px' })
    observer.observe(target)
    return () => {
      active = false
      observer.disconnect()
    }
  }, [managedSource])

  const requestedSource = managedSource && (visibleSource === managedSource || typeof IntersectionObserver === 'undefined')
    ? managedSource
    : null
  const { src } = useImageBlob(requestedSource)
  const image = managedSource ? (requestedSource ? src : null) : source

  return (
    <Artwork ref={element} className={className} aria-hidden="true" $wide={wide}>
      {image ? <img src={image} loading="lazy" alt="" /> : <Film size={24} />}
    </Artwork>
  )
}
