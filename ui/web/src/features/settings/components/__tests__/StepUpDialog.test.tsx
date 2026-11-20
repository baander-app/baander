import { useEffect, useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { render } from '../../../../../tests/test-utils'
import { StepUpDialog, type StepUpCredentials } from '../StepUpDialog'

const onConfirm = vi.fn<(credentials: StepUpCredentials) => Promise<boolean>>()

function Harness({ expose }: { expose: (setOpen: (open: boolean) => void) => void }) {
  const [open, setOpen] = useState(true)
  useEffect(() => expose(setOpen), [expose])

  return (
    <>
      <StepUpDialog
        open={open}
        onOpenChange={setOpen}
        title="Confirm change"
        description="Prove it is you."
        submitLabel="Confirm"
        fallbackError="Something went wrong"
        onConfirm={onConfirm}
      />
    </>
  )
}

function rateLimited() {
  const config = { headers: new AxiosHeaders() }

  return new AxiosError('Too many requests', 'ERR_BAD_REQUEST', config, undefined, {
    data: {},
    status: 429,
    statusText: 'Too Many Requests',
    headers: {},
    config,
  })
}

describe('StepUpDialog', () => {
  afterEach(() => {
    cleanup()
    onConfirm.mockReset()
  })

  it('stays open and disables Cancel while a submit is in flight', async () => {
    onConfirm.mockReturnValue(new Promise<boolean>(() => {}))
    const user = userEvent.setup()
    render(<Harness expose={() => {}} />)

    await user.type(screen.getByLabelText('Current password'), 'current-password')
    await user.click(screen.getByRole('button', { name: 'Confirm' }))

    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled()
    await user.keyboard('{Escape}')

    expect(screen.getByRole('dialog', { name: 'Confirm change' })).toBeInTheDocument()
  })

  it('ignores a late refusal from a submit the dialog was closed under', async () => {
    let reject: (reason: unknown) => void = () => {}
    onConfirm.mockReturnValue(new Promise<boolean>((_, r) => { reject = r }))
    const user = userEvent.setup()
    let setOpenFromParent: (open: boolean) => void = () => {}
    render(<Harness expose={(setOpen) => { setOpenFromParent = setOpen }} />)

    await user.type(screen.getByLabelText('Current password'), 'current-password')
    await user.click(screen.getByRole('button', { name: 'Confirm' }))
    act(() => setOpenFromParent(false))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    act(() => setOpenFromParent(true))

    await act(async () => { reject(rateLimited()) })

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirm' })).toBeEnabled()
  })
})
