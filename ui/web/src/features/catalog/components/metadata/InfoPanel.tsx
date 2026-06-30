import styled from 'styled-components'
import { useEffect, useMemo, useRef } from 'react'
import { RefreshCw } from 'lucide-react'
import { Separator } from '@/shared/components/ui/separator'
import { Button } from '@/shared/components/ui/button'
import { useGetSongShow, useGetAlbumShow, useGetArtistShow } from '@/shared/api-client/gen/endpoints'
import { asSongFromData, asAlbumFromData } from '../../utils/api-adapters'
import { getFieldConfig } from './field-config'
import { MetadataField } from './MetadataField'
import { ProtectionSection } from './ProtectionSection'
import { CoverImageUpload } from './CoverImageUpload'
import { useMetadataForm } from './use-metadata-form'
import type { EntityFieldConfig } from './field-config'

const Container = styled.div`
  display: flex;
  flex-direction: column;
`

const LoadingContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const LoadingField = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const LoadingLabel = styled.div`
  height: 0.75rem;
  width: 4rem;
  border-radius: var(--radius-sm);
  background-color: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const LoadingInput = styled.div`
  height: 2rem;
  width: 100%;
  border-radius: var(--radius-lg);
  background-color: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const ErrorContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 2rem 0;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SectionLabel = styled.span`
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
`

const SyncButton = styled(Button)`
  width: 100%;
  gap: 0.375rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

type EntityType = 'song' | 'album' | 'artist'

interface InfoPanelProps {
  entityType: EntityType
  publicId: string
}

/* eslint-disable @typescript-eslint/no-explicit-any */

export function InfoPanel({ entityType, publicId }: InfoPanelProps) {
  const config = useMemo(() => getFieldConfig(entityType), [entityType])
  const prevPublicIdRef = useRef(publicId)

  // Fetch entity data
  const songQuery = useGetSongShow(publicId, {
    query: { enabled: entityType === 'song' },
  })
  const albumQuery = useGetAlbumShow(publicId, {
    query: { enabled: entityType === 'album' },
  })
  const artistQuery = useGetArtistShow(publicId, {
    query: { enabled: entityType === 'artist' },
  })

  const isLoading =
    (entityType === 'song' && songQuery.isLoading) ||
    (entityType === 'album' && albumQuery.isLoading) ||
    (entityType === 'artist' && artistQuery.isLoading)

  const isError =
    (entityType === 'song' && songQuery.isError) ||
    (entityType === 'album' && albumQuery.isError) ||
    (entityType === 'artist' && artistQuery.isError)

  const rawData =
    entityType === 'song' ? songQuery.data :
    entityType === 'album' ? albumQuery.data :
    artistQuery.data

  // Extract entity data and locked fields
  const entity = useMemo(() => {
    if (!rawData) return null

    if (entityType === 'song') {
      const song = asSongFromData(rawData)
      if (!song) return null
      const raw = (rawData as any)?.data as any
      return {
        data: {
          title: song.title,
          track: song.track,
          disc: song.disc,
          year: song.year,
          comment: raw?.comment ?? null,
          lyrics: song.lyrics,
          explicit: song.explicit,
          path: song.path,
          bitrate: song.bitrate ? `${song.bitrate} kbps` : null,
          mimeType: raw?.mimeType ?? null,
          mbid: raw?.mbid ?? null,
          discogsId: raw?.discogsId ?? null,
          spotifyId: raw?.spotifyId ?? null,
        },
        lockedFields: Array.isArray(raw?.lockedFields) ? raw.lockedFields : [],
        coverImage: null,
      }
    }

    if (entityType === 'album') {
      const album = asAlbumFromData(rawData)
      if (!album) return null
      const raw = (rawData as any)?.data as any
      return {
        data: {
          title: album.title,
          type: album.type,
          year: album.year,
          label: album.label,
          catalogNumber: album.catalogNumber,
          barcode: album.barcode,
          country: album.country,
          language: album.language,
          disambiguation: album.disambiguation,
          annotation: album.annotation,
          mbid: album.mbid,
          discogsId: album.discogsId,
          spotifyId: album.spotifyId,
        },
        lockedFields: Array.isArray(raw?.lockedFields) ? raw.lockedFields : [],
        coverImage: raw?.coverImage ?? null,
      }
    }

    // Artist
    const raw = (rawData as any)?.data as any
    if (!raw) return null
    return {
      data: {
        name: raw.name,
        type: raw.type,
        country: raw.country,
        gender: raw.gender,
        sortName: raw.sortName,
        disambiguation: raw.disambiguation,
        biography: raw.biography,
        mbid: raw.mbid,
        discogsId: raw.discogsId,
        spotifyId: raw.spotifyId,
      },
      lockedFields: Array.isArray(raw?.lockedFields) ? raw.lockedFields : [],
      coverImage: raw?.coverImage ?? null,
    }
  }, [rawData, entityType])

  const {
    formState,
    lockedFields,
    dirty,
    isSaving,
    updateField,
    toggleLock,
    resetForm,
  } = useMetadataForm({
    entityType,
    publicId,
    initialData: entity?.data ?? {},
    lockedFields: entity?.lockedFields ?? [],
  })

  // Reset form when entity data changes or publicId changes
  useEffect(() => {
    if (entity) {
      resetForm(entity.data, entity.lockedFields)
    }
  }, [entity, resetForm])

  // Also reset if publicId changes entirely
  useEffect(() => {
    if (prevPublicIdRef.current !== publicId) {
      prevPublicIdRef.current = publicId
    }
  }, [publicId])

  if (isLoading) {
    return (
      <LoadingContainer>
        {Array.from({ length: 4 }).map((_, i) => (
          <LoadingField key={i}>
            <LoadingLabel />
            <LoadingInput />
          </LoadingField>
        ))}
      </LoadingContainer>
    )
  }

  if (isError || !entity) {
    return (
      <ErrorContainer>
        <ErrorText>Failed to load {entityType}</ErrorText>
      </ErrorContainer>
    )
  }

  return (
    <Container>
      {/* Cover image upload for albums and artists */}
      {(entityType === 'album' || entityType === 'artist') && (
        <>
          <CoverImageUpload
            entityType={entityType}
            publicId={publicId}
            coverImage={entity.coverImage}
          />
          <Separator style={{ margin: '0.5rem 0' }} />
        </>
      )}

      {config.sections.map((section, sectionIndex) => {
        const hasEditableFields = section.fields.some((f) => !f.readOnly)
        const hasData = section.fields.some((f) => {
          const val = formState[f.key]
          return val !== null && val !== undefined && val !== ''
        })

        // Show all sections that have editable fields, or read-only sections that have data
        if (!hasEditableFields && !hasData) return null

        return (
          <div key={section.label}>
            {sectionIndex > 0 && <Separator style={{ margin: '0.5rem 0' }} />}
            <div style={{ marginBottom: '0.25rem' }}>
              <SectionLabel>{section.label}</SectionLabel>
            </div>
            {section.fields.map((field) => {
              const value = formState[field.key]

              // Skip empty read-only fields
              if (field.readOnly && (value === null || value === undefined || value === '')) {
                return null
              }

              return (
                <MetadataField
                  key={field.key}
                  field={field}
                  value={value}
                  isLocked={lockedFields.includes(field.key)}
                  isDirty={dirty.has(field.key)}
                  isSaving={isSaving}
                  onChange={(v) => updateField(field.key, v)}
                  onToggleLock={() => toggleLock(field.key)}
                />
              )
            })}
          </div>
        )
      })}

      {/* Protection section — only if there are lockable fields */}
      <Separator style={{ margin: '0.5rem 0' }} />
      <ProtectionSection
        fields={getAllLockableFields(config)}
        lockedFields={lockedFields}
        onToggleLock={toggleLock}
      />

      {/* Sync placeholder */}
      <Separator style={{ margin: '0.5rem 0' }} />
      <SyncButton variant="ghost" size="sm">
        <RefreshCw size={12} />
        Sync from MusicBrainz
      </SyncButton>
    </Container>
  )
}

function getAllLockableFields(config: EntityFieldConfig) {
  return config.sections.flatMap((s) => s.fields.filter((f) => f.lockable))
}
