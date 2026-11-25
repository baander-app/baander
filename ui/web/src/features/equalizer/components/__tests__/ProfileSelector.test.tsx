import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { toast } from 'sonner'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useEqProfilesStore } from '../../stores/eq-profiles-store'
import { ProfileSelector } from '../ProfileSelector'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}))

function refusal(status: number, message: string): AxiosError {
  const config = { headers: new AxiosHeaders() }

  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_REQUEST', config, undefined, {
    data: { error: { code: status, message, details: { reason: 'eq_profile_limit', limit: 50 } } },
    status,
    statusText: 'Error',
    headers: {},
    config,
  })
}

const profile = {
  publicId: 'EqProfilePublicIdent1',
  name: 'Studio headphones',
  icon: 'headphones',
  deviceId: 'audio-output:studio-headphones',
  payload: {},
  isDefault: false,
  sortOrder: 0,
  version: 1,
}

beforeEach(() => {
  vi.clearAllMocks()
  useEqProfilesStore.setState(useEqProfilesStore.getInitialState(), true)
  vi.mocked(AXIOS_INSTANCE.get).mockResolvedValue({ data: { data: { profiles: [profile] } } })
  vi.mocked(AXIOS_INSTANCE.post).mockResolvedValue({ data: {} })
  vi.mocked(AXIOS_INSTANCE.put).mockResolvedValue({ data: { data: profile } })
  vi.mocked(AXIOS_INSTANCE.delete).mockResolvedValue({ data: {} })
})

afterEach(cleanup)

it('activates, saves and deletes a public profile while preserving its audio-output token', async () => {
  const user = userEvent.setup()
  render(<ProfileSelector />)

  await user.click(await screen.findByText('Studio headphones'))
  expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith(`/api/user/eq-profiles/${profile.publicId}/activate`)
  expect(useEqProfilesStore.getState().activeProfileId).toBe(profile.publicId)
  expect(useEqProfilesStore.getState().profiles[0].deviceId).toBe(profile.deviceId)

  await user.click(screen.getByRole('button', { name: 'Save' }))
  await waitFor(() => expect(AXIOS_INSTANCE.put).toHaveBeenCalledWith(
    `/api/user/eq-profiles/${profile.publicId}`,
    expect.objectContaining({ payload: expect.any(Object) }),
  ))
  await user.click(screen.getByRole('button', { name: 'Del' }))
  await waitFor(() => expect(AXIOS_INSTANCE.delete).toHaveBeenCalledWith(`/api/user/eq-profiles/${profile.publicId}`))
  await waitFor(() => expect(useEqProfilesStore.getState().profiles).toEqual([]))
})

it('bounds the profile name and shows the server refusal instead of saving the profile locally', async () => {
  const user = userEvent.setup()
  vi.mocked(AXIOS_INSTANCE.post).mockRejectedValueOnce(refusal(409, 'A user may keep at most 50 EQ device profiles.'))
  render(<ProfileSelector />)
  await screen.findByText('Studio headphones')

  await user.click(screen.getByRole('button', { name: '+ New' }))
  const name = screen.getByPlaceholderText('Profile name')
  expect(name).toHaveAttribute('maxLength', '255')
  await user.type(name, 'One too many{Enter}')

  await waitFor(() => expect(toast.error).toHaveBeenCalledWith('A user may keep at most 50 EQ device profiles.'))
  expect(toast.warning).not.toHaveBeenCalled()
  expect(useEqProfilesStore.getState().profiles.map((stored) => stored.name)).toEqual(['Studio headphones'])
})

it('keeps the local fallback when the server cannot be reached', async () => {
  const user = userEvent.setup()
  vi.mocked(AXIOS_INSTANCE.post).mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK'))
  render(<ProfileSelector />)
  await screen.findByText('Studio headphones')

  await user.click(screen.getByRole('button', { name: '+ New' }))
  await user.type(screen.getByPlaceholderText('Profile name'), 'Offline{Enter}')

  await waitFor(() => expect(toast.warning).toHaveBeenCalledWith('Could not reach server — profile saved locally.'))
  expect(useEqProfilesStore.getState().profiles.map((stored) => stored.name)).toContain('Offline')
})
