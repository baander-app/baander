import { useState, useCallback, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  usePatchSongUpdate,
  usePatchAlbumUpdate,
  usePatchArtistUpdate,
  getGetSongShowQueryKey,
  getGetAlbumShowQueryKey,
  getGetArtistShowQueryKey,
} from '@/shared/api-client/gen/endpoints'

type EntityType = 'song' | 'album' | 'artist'

interface UseMetadataFormOptions {
  entityType: EntityType
  publicId: string
  initialData: Record<string, unknown>
  lockedFields: string[]
}

export function useMetadataForm({
  entityType,
  publicId,
  initialData,
  lockedFields: initialLocked,
}: UseMetadataFormOptions) {
  const [formState, setFormState] = useState<Record<string, unknown>>(() => ({ ...initialData }))
  const [lockedFields, setLockedFields] = useState<string[]>(() => [...initialLocked])
  const [dirty, setDirty] = useState<Set<string>>(new Set())
  const [isSaving, setIsSaving] = useState(false)
  const queryClient = useQueryClient()
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const patchSong = usePatchSongUpdate()
  const patchAlbum = usePatchAlbumUpdate()
  const patchArtist = usePatchArtistUpdate()

  const getQueryKey = useCallback(() => {
    switch (entityType) {
      case 'song': return getGetSongShowQueryKey(publicId)
      case 'album': return getGetAlbumShowQueryKey(publicId)
      case 'artist': return getGetArtistShowQueryKey(publicId)
    }
  }, [entityType, publicId])

  const buildPayload = useCallback(
    (fields: Set<string>, state: Record<string, unknown>, locks: string[]) => {
      const payload: Record<string, unknown> = {}
      for (const key of fields) {
        if (key in state) {
          payload[key] = state[key] ?? null
        }
      }
      // Always send lockedFields when saving
      payload.lockedFields = locks
      return payload
    },
    [],
  )

  const save = useCallback(async () => {
    if (dirty.size === 0) return

    setIsSaving(true)
    const payload = buildPayload(dirty, formState, lockedFields)

    try {
      switch (entityType) {
        case 'song':
          await patchSong.mutateAsync({ publicId, data: payload })
          break
        case 'album':
          await patchAlbum.mutateAsync({ publicId, data: payload })
          break
        case 'artist':
          await patchArtist.mutateAsync({ publicId, data: payload })
          break
      }

      // Invalidate queries to refresh data
      queryClient.invalidateQueries({ queryKey: getQueryKey() })

      // Also invalidate list queries
      queryClient.invalidateQueries({
        predicate: (query) => {
          const key = query.queryKey[0]
          return typeof key === 'string' && key.includes(entityType)
        },
      })

      setDirty(new Set())
      toast.success(`${entityType.charAt(0).toUpperCase() + entityType.slice(1)} updated`)
    } catch {
      toast.error('Failed to save changes')
    } finally {
      setIsSaving(false)
    }
  }, [dirty, formState, lockedFields, entityType, publicId, patchSong, patchAlbum, patchArtist, queryClient, getQueryKey, buildPayload])

  const updateField = useCallback(
    (key: string, value: unknown) => {
      setFormState((prev) => ({ ...prev, [key]: value }))
      setDirty((prev) => new Set(prev).add(key))

      // Auto-save with debounce (800ms)
      if (saveTimerRef.current) {
        clearTimeout(saveTimerRef.current)
      }
      saveTimerRef.current = setTimeout(() => {
        save()
      }, 800)
    },
    [save],
  )

  const toggleLock = useCallback(
    (field: string) => {
      setLockedFields((prev) => {
        const next = prev.includes(field)
          ? prev.filter((f) => f !== field)
          : [...prev, field]

        // Save lock changes immediately
        const payload: Record<string, unknown> = { lockedFields: next }
        switch (entityType) {
          case 'song':
            patchSong.mutate({ publicId, data: payload })
            break
          case 'album':
            patchAlbum.mutate({ publicId, data: payload })
            break
          case 'artist':
            patchArtist.mutate({ publicId, data: payload })
            break
        }

        return next
      })
    },
    [entityType, publicId, patchSong, patchAlbum, patchArtist],
  )

  // Reset form when initial data changes (e.g. after query refresh)
  const resetForm = useCallback(
    (newData: Record<string, unknown>, newLocks: string[]) => {
      setFormState({ ...newData })
      setLockedFields([...newLocks])
      setDirty(new Set())
    },
    [],
  )

  return {
    formState,
    lockedFields,
    dirty,
    isSaving,
    updateField,
    toggleLock,
    save,
    resetForm,
  }
}
