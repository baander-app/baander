import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useEqProfilesStore } from '../../stores/eq-profiles-store'
import { ProfileSelector } from '../ProfileSelector'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

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
