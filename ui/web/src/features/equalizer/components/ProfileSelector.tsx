import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { useCallback, useEffect, useState } from 'react'
import { captureEqSettings, applyEqSettings } from '../stores/eq-settings'
import { useEqProfilesStore, type EqProfile, type EqProfileIcon } from '../stores/eq-profiles-store'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { Input } from '@/shared/components/ui/input'
import { Button } from '@/shared/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import styled, { css } from 'styled-components'
import { createLogger } from '@/shared/lib/logger'
import { toast } from 'sonner'

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

async function apiListProfiles(signal?: AbortSignal) {
  const res = await AXIOS_INSTANCE.get('/api/user/eq-profiles/', { signal })
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
  return { ...captureEqSettings() }
}

function applyProfilePayload(payload: Record<string, unknown>) {
  applyEqSettings(payload)
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
  border-bottom: 1px solid var(--color-border);
`

const NameInput = styled(Input)`
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  border: 1px solid var(--color-input-boundary);
  width: 100%;

  &::placeholder {
    color: var(--color-muted-foreground);
  }
`

const IconRow = styled.div`
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.375rem;
`

const IconButton = styled(Button).attrs({ variant: 'ghost', size: 'xs' })<{ $active: boolean }>`
  padding: 0.125rem 0.375rem;
  border-radius: var(--radius-md);
  font-size: 0.875rem;
  border: none;
  cursor: pointer;
  transition: none;

  ${(p) => p.$active
    ? css`
      background-color: var(--color-primary);
      color: var(--color-primary-foreground);
      &:hover { background-color: var(--color-primary-hover); color: var(--color-primary-foreground); }
    `
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
  transition: none;

  ${(p) => p.$active
    ? css`
      background-color: var(--color-highlight);
      color: var(--color-highlight-foreground);
      border: 1px solid var(--color-accent-text);
    `
    : css`
      border: 1px solid transparent;
      &:hover { background-color: var(--color-muted); }
    `
  }
`

const ProfileIcon = styled.span`
  font-size: 0.875rem;
`

const ProfileName = styled.span`
  min-width: 0;
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
  const coordinator = useLiveCoordinator()
  const setProfiles = useEqProfilesStore((s) => s.setProfiles)
  const addProfile = useEqProfilesStore((s) => s.addProfile)
  const removeProfile = useEqProfilesStore((s) => s.removeProfile)
  const updateProfile = useEqProfilesStore((s) => s.updateProfile)
  const setActiveProfileId = useEqProfilesStore((s) => s.setActiveProfileId)
  const setLoaded = useEqProfilesStore((s) => s.setLoaded)

  const [showCreate, setShowCreate] = useState(false)
  const [newName, setNewName] = useState('')
  const [newIcon, setNewIcon] = useState<EqProfileIcon>('custom')

  useEffect(() => {
    const controller = new AbortController()
    const read = async (signal: AbortSignal) => {
      const owner = captureOwnership()
      try {
        const list = await apiListProfiles(signal)
        if (signal.aborted || controller.signal.aborted || !isCurrentOwnership(owner)) return
        const firstLoad = !useEqProfilesStore.getState().loaded
        setProfiles(list)
        if (firstLoad && !useEqProfilesStore.getState().activeProfileId) {
          const def = list.find(profile => profile.isDefault)
          if (def) setActiveProfileId(def.publicId)
        }
      } catch (error) {
        if (signal.aborted || controller.signal.aborted || !isCurrentOwnership(owner)) return
        logger.warn('Failed to load EQ profiles:', error)
        setLoaded(true)
        if (coordinator) throw error
      }
    }
    const unregister = coordinator?.registerAdapter({
      id: 'eq-profiles', family: 'preferences', isActive: () => true, read,
    })
    if (!coordinator) read(controller.signal).catch(() => undefined)
    return () => { controller.abort(); unregister?.() }
  }, [coordinator, setProfiles, setActiveProfileId, setLoaded])

  const handleCreate = useCallback(async () => {
    if (!newName.trim()) return
    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['preferences'])
    try {
      const profile = await apiCreateProfile({
        name: newName.trim(),
        icon: newIcon,
      })
      if (!isCurrentOwnership(owner)) return
      addProfile(profile)
      setShowCreate(false)
      setNewName('')
    } catch {
      if (!isCurrentOwnership(owner)) return
      // Offline fallback: create locally
      toast.warning('Could not reach server — profile saved locally.')
      const local: EqProfile = {
        publicId: `local-${Date.now()}`,
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
    } finally {
      release?.()
    }
  }, [newName, newIcon, profiles.length, addProfile, coordinator])

  const handleSave = useCallback(async (id: string) => {
    const payload = captureCurrentPayload()
    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['preferences'])
    try {
      const updated = await apiUpdateProfile(id, { payload })
      if (!isCurrentOwnership(owner)) return
      updateProfile(id, { payload: updated.payload, version: updated.version })
    } catch {
      if (!isCurrentOwnership(owner)) return
      toast.warning('Could not reach server — profile saved locally.')
      updateProfile(id, { payload })
    } finally {
      release?.()
    }
  }, [updateProfile, coordinator])

  const handleActivate = useCallback(async (id: string) => {
    setActiveProfileId(id)
    const profile = useEqProfilesStore.getState().profiles.find((p) => p.publicId === id)
    if (profile?.payload && Object.keys(profile.payload).length > 0) {
      applyProfilePayload(profile.payload)
    }
    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['preferences'])
    try {
      await apiActivateProfile(id)
    } catch {
      if (!isCurrentOwnership(owner)) return
      toast.warning('Could not reach server — profile activated locally only.')
    } finally {
      release?.()
    }
  }, [setActiveProfileId, coordinator])

  const handleDelete = useCallback(async (id: string) => {
    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['preferences'])
    try {
      await apiDeleteProfile(id)
      if (!isCurrentOwnership(owner)) return
      removeProfile(id)
    } catch {
      if (!isCurrentOwnership(owner)) return
      toast.warning('Could not reach server — profile removed locally only.')
      removeProfile(id)
    } finally {
      release?.()
    }
  }, [removeProfile, coordinator])

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
              key={profile.publicId}
              $active={activeProfileId === profile.publicId}
              onClick={() => handleActivate(profile.publicId)}
            >
              <ProfileIcon>{ICON_LABEL[profile.icon]}</ProfileIcon>
              <ProfileName>{profile.name}</ProfileName>
              {profile.isDefault && (
                <DefaultLabel>Default</DefaultLabel>
              )}
              <Button
                variant="ghost"
                size="xs"
                onClick={(e) => { e.stopPropagation(); handleSave(profile.publicId) }}
                title="Save current settings to this profile"
              >
                Save
              </Button>
              {!profile.isDefault && (
                <Button
                  variant="ghost"
                  size="xs"
                  onClick={(e) => { e.stopPropagation(); handleDelete(profile.publicId) }}
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
