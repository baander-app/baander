import { advanceAuthorization } from '@/shared/live/ownership'
import { act, cleanup, fireEvent, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../../tests/test-utils'
import { EditJobDialog } from '../EditJobDialog'
import type { ScheduledJob } from '../../../api/scheduler-admin-api'

const { mutate, state } = vi.hoisted(() => ({ mutate: vi.fn(), state: {
  data: undefined as ScheduledJob | undefined,
  error: null as { status: number } | null,
} }))
vi.mock('../../../hooks/use-scheduler-admin', () => ({
  useUpdateScheduledJob: () => ({ mutate, isPending: false }),
  useSchedulableCommands: () => ({ data: { messenger: {}, console: {} } }),
  useScheduledJob: () => state,
}))
const job: ScheduledJob = {
  publicId: 'job', name: 'Scheduled job', expression: '* * * * *', jobType: 'messenger',
  command: 'work', status: 'active', description: null, parameters: {}, lastRunAt: null,
  nextRunAt: null, lastResult: null, runCount: 0, lastFailureAt: null, lastError: null,
  createdAt: '', updatedAt: '',
}
afterEach(() => { cleanup(); mutate.mockReset(); state.data = undefined; state.error = null })

describe('scheduled job editor', () => {
  it('receives a detail snapshot even when the list selection is stale and pauses conflicting writes', async () => {
    state.data = job
    const view = render(<EditJobDialog open job={job} onOpenChange={vi.fn()} />)
    fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'My name' } })
    state.data = { ...job, name: 'Remote name' }
    view.rerender(<EditJobDialog open job={job} onOpenChange={vi.fn()} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('This job changed')
    expect(screen.getAllByRole('textbox')[0]).toHaveValue('My name')
    expect(screen.getByRole('button', { name: 'Save Changes' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Keep my edits' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))
    expect(mutate).toHaveBeenCalledWith({ id: 'job', payload: expect.objectContaining({ name: 'My name' }) }, expect.any(Object))
  })

  it('purges protected fields on denial and ignores the retained stale snapshot after role loss', async () => {
    state.data = job
    const view = render(<EditJobDialog open job={job} onOpenChange={vi.fn()} />)
    fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'Recover name' } })
    state.error = { status: 403 }
    view.rerender(<EditJobDialog open job={job} onOpenChange={vi.fn()} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('unavailable')
    expect(screen.getAllByRole('textbox')[1]).toHaveValue('')
    act(() => { advanceAuthorization() })
    view.rerender(<EditJobDialog open job={job} onOpenChange={vi.fn()} />)
    expect(screen.getAllByRole('textbox')[1]).toHaveValue('')
    expect(screen.getAllByRole('textbox')[0]).toHaveValue('Recover name')
  })

  it('requires an explicit unavailable detail response before disabling saved entity edits', async () => {
    const view = render(<EditJobDialog open job={job} onOpenChange={vi.fn()} />)
    fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'Recover name' } })
    expect(screen.getByRole('button', { name: 'Save Changes' })).toBeEnabled()
    state.error = { status: 404 }
    view.rerender(<EditJobDialog open job={job} onOpenChange={vi.fn()} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('unavailable')
    expect(screen.getAllByRole('textbox')[0]).toHaveValue('Recover name')
    expect(screen.getByRole('button', { name: 'Save Changes' })).toBeDisabled()
  })
})
