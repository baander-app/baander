import { useCallback, useEffect, useRef } from 'react'
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
import { useDraft } from '@/shared/drafts/use-draft'
import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'

type EntityType = 'song' | 'album' | 'artist'

const NO_LOCKS: string[] = []

interface UseMetadataFormOptions {
  entityType: EntityType
  publicId: string
  initialData: Record<string, unknown>
  lockedFields: string[]
  unavailable?: boolean
  unauthorized?: boolean
}

export function useMetadataForm({
  entityType,
  publicId,
  initialData,
  lockedFields: initialLocked,
  unavailable = false,
  unauthorized = false,
}: UseMetadataFormOptions) {
  const draft = useDraft<Record<string, unknown>>({
    identity: `${entityType}:${publicId}`,
    initialData: { ...initialData, lockedFields: initialLocked },
  })
  const { receive, revokeAccess, beginSave, completeSave, failSave, resolveConflict, updateField: updateDraftField } = draft
  const lockedFields = (draft.values.lockedFields ?? NO_LOCKS) as string[]
  const queryClient = useQueryClient()
  const coordinator = useLiveCoordinator()
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const mounted = useRef<typeof beginSave | null>(null)
  const revokedSnapshot = useRef<Record<string, unknown> | null>(null)
  const lastServerData = useRef(initialData)
  const patchSong = usePatchSongUpdate()
  const patchAlbum = usePatchAlbumUpdate()
  const patchArtist = usePatchArtistUpdate()

  useEffect(() => {
    mounted.current = beginSave
    const unsubscribe = subscribeOwnership(() => {
      revokedSnapshot.current = lastServerData.current
      revokeAccess()
    })
    return () => {
      mounted.current = null
      unsubscribe()
      if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
    }
  }, [revokeAccess, beginSave])

  useEffect(() => {
    lastServerData.current = initialData
    if (unauthorized) {
      revokedSnapshot.current = initialData
      revokeAccess()
    } else if (unavailable) {
      receive(null)
    } else if (revokedSnapshot.current !== initialData) {
      receive({ ...initialData, lockedFields: initialLocked })
    }
  }, [initialData, initialLocked, unavailable, unauthorized, receive, revokeAccess])

  const saveRef = useRef<() => Promise<void>>(async () => {})
  const scheduleSave = useCallback(() => {
    if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
    saveTimerRef.current = setTimeout(() => {
      saveRef.current().catch(() => undefined)
    }, 800)
  }, [])

  const save = useCallback(async () => {
    const submission = beginSave()
    if (!submission) return
    const stamp = captureOwnership()
    const release = coordinator?.beginMutation(['catalog', 'lyrics', 'recommendations'])
    const payload: Record<string, unknown> = { lockedFields: submission.values.lockedFields }
    for (const key of submission.fields) payload[key] = submission.values[key] ?? null

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
      if (mounted.current !== beginSave || !isCurrentOwnership(stamp)) return
      completeSave(submission)
      const queryKey = entityType === 'song' ? getGetSongShowQueryKey(publicId)
        : entityType === 'album' ? getGetAlbumShowQueryKey(publicId)
          : getGetArtistShowQueryKey(publicId)
      // Mark caches stale while the coordinator's mutation hold still protects reads.
      await queryClient.invalidateQueries({ queryKey, refetchType: 'none' })
      if (mounted.current !== beginSave || !isCurrentOwnership(stamp)) return
      await queryClient.invalidateQueries({
        predicate: query => typeof query.queryKey[0] === 'string' && query.queryKey[0].includes(entityType),
        refetchType: coordinator ? 'none' : 'active',
      })
      if (mounted.current !== beginSave || !isCurrentOwnership(stamp)) return
      toast.success(`${entityType.charAt(0).toUpperCase() + entityType.slice(1)} updated`)
      scheduleSave()
    } catch {
      if (mounted.current !== beginSave || !isCurrentOwnership(stamp)) return
      failSave(submission)
      toast.error('Failed to save changes')
    } finally {
      release?.()
    }
  }, [beginSave, completeSave, failSave, coordinator, entityType, publicId, patchSong, patchAlbum, patchArtist, queryClient, scheduleSave])

  useEffect(() => {
    saveRef.current = save
  }, [save])

  const updateField = useCallback((key: string, value: unknown) => {
    updateDraftField(key, value)
    scheduleSave()
  }, [updateDraftField, scheduleSave])

  const toggleLock = useCallback((field: string) => {
    const locks = lockedFields
    updateDraftField('lockedFields', locks.includes(field) ? locks.filter(key => key !== field) : [...locks, field])
    saveRef.current().catch(() => undefined)
  }, [lockedFields, updateDraftField])

  const resolveDraft = useCallback((choice: 'server' | 'local') => {
    resolveConflict(choice)
    if (choice === 'local') scheduleSave()
  }, [resolveConflict, scheduleSave])

  return {
    formState: draft.values,
    lockedFields,
    dirty: draft.dirty,
    isSaving: draft.pending !== null,
    status: draft.status,
    canSave: draft.canSave,
    updateField,
    toggleLock,
    save,
    resolveConflict: resolveDraft,
  }
}
