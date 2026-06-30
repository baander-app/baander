import { useCallback, useEffect, useState } from 'react'
import { useEqBandsStore } from '../stores/eq-bands-store'
import { useEqProcessingStore } from '../stores/eq-processing-store'
import { useEqProfilesStore, type EqProfile, type EqProfileIcon } from '../stores/eq-profiles-store'
import { reapplyAllEqState } from '../stores/eq-reapply'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { Button } from '@/shared/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import styled, { css } from 'styled-components'
import { createLogger } from '@/shared/lib/logger'

const logger = createLogger('ProfileSelector')

const ICON_LABEL: Record<EqProfileIcon, string> = {
  headphones: 'HP',
  speakers: 'SPK',
  'hifi-speaker': 'HiFi',
  'wireless-speaker': 'Wifi',
  car: 'Car',
  tv: 'TV',
  monitor: 'Mon',
  custom: '...',
}

async function apiListProfiles() {
  const res = await AXIOS_INSTANCE.get('/api/user/eq-profiles/')
  return (res.data?.data?.profiles ?? res.data?.profiles ?? []) as EqProfile[]
}

async function apiCreateProfile(data: { name: string; icon: string; deviceId?: string }) {
  const res = await AXIOS_INSTANCE.post('/api/user/eq-profiles/', data)
  return (res.data?.data ?? res.data) as EqProfile
}

async function apiUpdateProfile(id: string, data: Record<string, unknown>) {
  const res = await AXIOS_INSTANCE.put(`/api/user/eq-profiles/${id}`, data)
  return (res.data?.data ?? res.data) as EqProfile
}

async function apiDeleteProfile(id: string) {
  await AXIOS_INSTANCE.delete(`/api/user/eq-profiles/${id}`)
}

async function apiActivateProfile(id: string) {
  await AXIOS_INSTANCE.post(`/api/user/eq-profiles/${id}/activate`)
}

function captureCurrentPayload(): Record<string, unknown> {
  const bandsState = useEqBandsStore.getState()
  const processingState = useEqProcessingStore.getState()
  return {
    enabled: bandsState.enabled,
    bands: bandsState.bands,
    preset: bandsState.preset,
    compressionEnabled: processingState.compressionEnabled,
    compressorThreshold: processingState.compressorThreshold,
    compressorRatio: processingState.compressorRatio,
    compressorKnee: processingState.compressorKnee,
    compressorAttack: processingState.compressorAttack,
    compressorRelease: processingState.compressorRelease,
    masterGain: processingState.masterGain,
    normalizationEnabled: processingState.normalizationEnabled,
    targetLufs: processingState.targetLufs,
    stereoEnabled: processingState.stereoEnabled,
    stereoWidth: processingState.stereoWidth,
    stereoMode: processingState.stereoMode,
    crossfeedEnabled: processingState.crossfeedEnabled,
    crossfeedPreset: processingState.crossfeedPreset,
    loudnessContourEnabled: processingState.loudnessContourEnabled,
    chainOrder: processingState.chainOrder,
  }
}

function applyProfilePayload(payload: Record<string, unknown>) {
  const bandsState = useEqBandsStore.getState()

  if (payload.bands && Array.isArray(payload.bands)) {
    useEqBandsStore.setState({
      bands: payload.bands as Array<{ gain: number; q: number }>,
      enabled: payload.enabled as boolean ?? true,
      preset: (payload.preset as string ?? 'FLAT') as typeof bandsState.preset,
    })
  }

  const keys = [
    'compressionEnabled', 'compressorThreshold', 'compressorRatio',
    'compressorKnee', 'compressorAttack', 'compressorRelease',
    'masterGain', 'normalizationEnabled', 'targetLufs',
    'stereoEnabled', 'stereoWidth', 'stereoMode',
    'crossfeedEnabled', 'crossfeedPreset', 'loudnessContourEnabled',
  ] as const

  const updates: Record<string, unknown> = {}
  for (const key of keys) {
    if (payload[key] !== undefined) updates[key] = payload[key]
  }
  if (payload.chainOrder) updates.chainOrder = payload.chainOrder

  useEqProcessingStore.setState(updates)
  reapplyAllEqState()
}

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const CreateForm = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid rgba(var(--color-border-rgb, 128 128 128), 0.5);
`

const NameInput = styled.input`
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  border: none;
  outline: none;
  width: 100%;

  &::placeholder {
    color: var(--color-muted-foreground);
  }
`

const IconRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const IconButton = styled.button<{ $active: boolean }>`
  padding: 0.125rem 0.375rem;
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s;

  ${(p) => p.$active
    ? css`background-color: var(--color-primary); color: var(--color-primary-foreground);`
    : css`background-color: transparent; &:hover { background-color: var(--color-muted); }`
  }
`

const FormActions = styled.div`
  display: flex;
  gap: 0.375rem;
`

const EmptyText = styled.p`
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const ProfileList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const ProfileItem = styled.div<{ $active: boolean }>`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.375rem 0.5rem;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: background-color 0.15s;

  ${(p) => p.$active
    ? css`
      background-color: rgba(var(--color-primary-rgb, 0 0 0), 0.1);
      border: 1px solid rgba(var(--color-primary-rgb, 0 0 0), 0.2);
    `
    : css`
      border: 1px solid transparent;
      &:hover { background-color: rgba(var(--color-muted-rgb, 128 128 128), 0.5); }
    `
  }
`

const ProfileIcon = styled.span`
  font-size: 0.875rem;
`

const ProfileName = styled.span`
  font-size: 11px;
  font-weight: 500;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const DefaultLabel = styled.span`
  font-size: 9px;
  color: var(--color-muted-foreground);
  text-transform: uppercase;
`

export function ProfileSelector() {
  const profiles = useEqProfilesStore((s) => s.profiles)
  const activeProfileId = useEqProfilesStore((s) => s.activeProfileId)
  const loaded = useEqProfilesStore((s) => s.loaded)
  const setProfiles = useEqProfilesStore((s) => s.setProfiles)
  const addProfile = useEqProfilesStore((s) => s.addProfile)
  const removeProfile = useEqProfilesStore((s) => s.removeProfile)
  const updateProfile = useEqProfilesStore((s) => s.updateProfile)
  const setActiveProfileId = useEqProfilesStore((s) => s.setActiveProfileId)
  const setLoaded = useEqProfilesStore((s) => s.setLoaded)

  const [showCreate, setShowCreate] = useState(false)
  const [newName, setNewName] = useState('')
  const [newIcon, setNewIcon] = useState<EqProfileIcon>('custom')

  // Load profiles on mount
  useEffect(() => {
    if (loaded) return
    apiListProfiles()
      .then((list) => {
        setProfiles(list)
        // Auto-activate default
        const def = list.find((p) => p.isDefault)
        if (def) setActiveProfileId(def.id)
      })
      .catch((err) => {
        logger.warn('Failed to load EQ profiles:', err)
        setLoaded(true)
      })
  }, [loaded, setProfiles, setActiveProfileId, setLoaded])

  const handleCreate = useCallback(async () => {
    if (!newName.trim()) return
    try {
      const profile = await apiCreateProfile({
        name: newName.trim(),
        icon: newIcon,
      })
      addProfile(profile)
      setShowCreate(false)
      setNewName('')
    } catch {
      // Offline fallback: create locally
      const local: EqProfile = {
        id: `local-${Date.now()}`,
        name: newName.trim(),
        icon: newIcon,
        payload: {},
        isDefault: profiles.length === 0,
        sortOrder: profiles.length,
        version: 0,
      }
      addProfile(local)
      setShowCreate(false)
      setNewName('')
    }
  }, [newName, newIcon, profiles.length, addProfile])

  const handleSave = useCallback(async (id: string) => {
    const payload = captureCurrentPayload()
    try {
      const updated = await apiUpdateProfile(id, { payload })
      updateProfile(id, { payload: updated.payload, version: updated.version })
    } catch {
      updateProfile(id, { payload })
    }
  }, [updateProfile])

  const handleActivate = useCallback(async (id: string) => {
    setActiveProfileId(id)
    const profile = useEqProfilesStore.getState().profiles.find((p) => p.id === id)
    if (profile?.payload && Object.keys(profile.payload).length > 0) {
      applyProfilePayload(profile.payload)
    }
    try {
      await apiActivateProfile(id)
    } catch {
      // Offline
    }
  }, [setActiveProfileId])

  const handleDelete = useCallback(async (id: string) => {
    try {
      await apiDeleteProfile(id)
      removeProfile(id)
    } catch {
      removeProfile(id)
    }
  }, [removeProfile])

  return (
    <Card>
      <CardHeader>
        <HeaderRow>
          <CardTitle>Device Profiles</CardTitle>
          <Button variant="ghost" size="xs" onClick={() => setShowCreate(!showCreate)}>
            + New
          </Button>
        </HeaderRow>
      </CardHeader>
      <CardContent>
        {/* Create form */}
        {showCreate && (
          <CreateForm>
            <NameInput
              type="text"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              placeholder="Profile name"
              autoFocus
              onKeyDown={(e) => { if (e.key === 'Enter') handleCreate() }}
            />
            <IconRow>
              {(Object.keys(ICON_LABEL) as EqProfileIcon[]).map((icon) => (
                <IconButton
                  key={icon}
                  $active={newIcon === icon}
                  onClick={() => setNewIcon(icon)}
                  title={icon}
                >
                  {ICON_LABEL[icon]}
                </IconButton>
              ))}
            </IconRow>
            <FormActions>
              <Button size="xs" onClick={handleCreate}>Create</Button>
              <Button size="xs" variant="ghost" onClick={() => setShowCreate(false)}>Cancel</Button>
            </FormActions>
          </CreateForm>
        )}

        {/* Profile list */}
        {profiles.length === 0 && !showCreate && (
          <EmptyText>
            No profiles yet. Create one to save EQ settings per device.
          </EmptyText>
        )}
        <ProfileList>
          {profiles.map((profile) => (
            <ProfileItem
              key={profile.id}
              $active={activeProfileId === profile.id}
              onClick={() => handleActivate(profile.id)}
            >
              <ProfileIcon>{ICON_LABEL[profile.icon]}</ProfileIcon>
              <ProfileName>{profile.name}</ProfileName>
              {profile.isDefault && (
                <DefaultLabel>Default</DefaultLabel>
              )}
              <Button
                variant="ghost"
                size="xs"
                onClick={(e) => { e.stopPropagation(); handleSave(profile.id) }}
                title="Save current settings to this profile"
              >
                Save
              </Button>
              {!profile.isDefault && (
                <Button
                  variant="ghost"
                  size="xs"
                  onClick={(e) => { e.stopPropagation(); handleDelete(profile.id) }}
                  title="Delete profile"
                >
                  Del
                </Button>
              )}
            </ProfileItem>
          ))}
        </ProfileList>
      </CardContent>
    </Card>
  )
}
