import styled from 'styled-components'
import { useRef, useState } from 'react'
import { Camera, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
`

const Label = styled.span`
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
`

const ImageArea = styled.div`
  position: relative;
  aspect-ratio: 1;
  width: 100%;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-secondary);
`

const CoverImage = styled.img`
  height: 100%;
  width: 100%;
  object-fit: cover;
`

const Placeholder = styled.div`
  display: flex;
  height: 100%;
  width: 100%;
  align-items: center;
  justify-content: center;
`

const HoverOverlay = styled.div`
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  background-color: rgba(0, 0, 0, 0.6);
  opacity: 0;
  transition: opacity 150ms;

  ${ImageArea}:hover & {
    opacity: 1;
  }
`

const OverlayButton = styled.button`
  border-radius: 50%;
  background-color: rgba(255, 255, 255, 0.2);
  padding: 0.5rem;
  backdrop-filter: blur(4px);
  transition: background-color 150ms;
  border: none;
  cursor: pointer;

  &:hover {
    background-color: rgba(255, 255, 255, 0.3);
  }

  &:disabled {
    cursor: not-allowed;
  }
`

const LoadingOverlay = styled.div`
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(0, 0, 0, 0.4);
`

const Spinner = styled.div`
  height: 1.25rem;
  width: 1.25rem;
  animation: spin 1s linear infinite;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: white;
`

const HiddenInput = styled.input`
  display: none;
`

interface CoverImageUploadProps {
  entityType: 'album' | 'artist'
  publicId: string
  coverImage: { url: string; blurhash?: string | null } | null | undefined
}

export function CoverImageUpload({ entityType, publicId, coverImage }: CoverImageUploadProps) {
  const fileRef = useRef<HTMLInputElement>(null)
  const [isUploading, setIsUploading] = useState(false)
  const queryClient = useQueryClient()

  const handleUpload = async (file: File) => {
    if (!file.type.match(/^image\/(jpeg|png|webp)$/)) {
      toast.error('Only JPEG, PNG, and WebP images are supported')
      return
    }
    if (file.size > 10 * 1024 * 1024) {
      toast.error('Image must be under 10 MB')
      return
    }

    setIsUploading(true)
    const form = new FormData()
    form.append('cover', file)

    try {
      await AXIOS_INSTANCE.post(`/api/${entityType}s/${publicId}/cover`, form)
      await queryClient.invalidateQueries({ queryKey: [`get${capitalize(entityType)}Show`, publicId] })
      toast.success('Image uploaded')
    } catch {
      toast.error('Failed to upload image')
    } finally {
      setIsUploading(false)
    }
  }

  const handleDelete = async () => {
    try {
      await AXIOS_INSTANCE.delete(`/api/${entityType}s/${publicId}/cover`)
      await queryClient.invalidateQueries({ queryKey: [`get${capitalize(entityType)}Show`, publicId] })
      toast.success('Image removed')
    } catch {
      toast.error('Failed to remove image')
    }
  }

  const label = entityType === 'album' ? 'Cover Art' : 'Photo'

  return (
    <Container>
      <Label>{label}</Label>
      <ImageArea>
        {coverImage?.url ? (
          <CoverImage src={coverImage.url} alt={label} />
        ) : (
          <Placeholder>
            <Camera size={24} style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 40%, transparent)' }} />
          </Placeholder>
        )}

        {/* Hover overlay */}
        <HoverOverlay>
          <OverlayButton
            type="button"
            onClick={() => fileRef.current?.click()}
            disabled={isUploading}
          >
            <Camera size={16} style={{ color: 'white' }} />
          </OverlayButton>
          {coverImage?.url && (
            <OverlayButton
              type="button"
              onClick={handleDelete}
            >
              <Trash2 size={16} style={{ color: 'white' }} />
            </OverlayButton>
          )}
        </HoverOverlay>

        {isUploading && (
          <LoadingOverlay>
            <Spinner />
          </LoadingOverlay>
        )}
      </ImageArea>

      <HiddenInput
        ref={fileRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        onChange={(e) => {
          const file = e.target.files?.[0]
          if (file) handleUpload(file)
          e.target.value = ''
        }}
      />
    </Container>
  )
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1)
}
