import { act, fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import { SidebarEditor } from '@/features/layout/components/SidebarEditor'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { put: vi.fn(), delete: vi.fn() } }))
beforeEach(() => {
  useSidebarStore.setState(useSidebarStore.getInitialState(), true)
  useMediaModeStore.getState().setActiveMedia('music')
})
it('initializes each opening from current schema and an old save cannot close a reopened editor', async () => {
  let resolve!: (value: unknown) => void
  vi.mocked(AXIOS_INSTANCE.put).mockReturnValueOnce(new Promise((yes) => { resolve = yes }))
  useSidebarStore.getState().setEditorOpen(true)
  render(<SidebarEditor />)
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  act(() => {
    useSidebarStore.getState().setEditorOpen(false)
  })
  act(() => {
    useSidebarStore.getState().setSchema('music', { mediaType: 'music', sections: [{ id: 'custom', label: 'Fresh schema', type: 'navigation', items: [] }] })
    useSidebarStore.getState().setEditorOpen(true)
  })
  expect(screen.getByText('Fresh schema')).toBeInTheDocument()
  await act(async () => { resolve({ data: {} }); await Promise.resolve() })
  expect(useSidebarStore.getState().isEditorOpen).toBe(true)
  expect(screen.getByText('Fresh schema')).toBeInTheDocument()
})
