import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useDraft } from '../use-draft'

function renderDraft() {
  return renderHook(() => useDraft({ identity: 'album:one', initialData: { title: 'old', year: 2000 } }))
}

describe('server snapshots and field drafts', () => {
  it('explicitly discards edits when reset returns the unchanged server baseline', () => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'my title'))
    act(() => result.current.resetToServer({ title: 'old', year: 2000 }))
    expect(result.current.values).toEqual({ title: 'old', year: 2000 })
    expect(result.current.status).toBe('clean')
    expect(result.current.canSave).toBe(false)
  })

  it.each(['pending', 'unavailable'] as const)('does not reset a %s draft', status => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'my title'))
    act(() => {
      if (status === 'pending') result.current.beginSave()
      else result.current.revokeAccess()
    })
    const previous = result.current.values
    act(() => result.current.resetToServer({ title: 'old', year: 2000 }))
    expect(result.current.values).toBe(previous)
    expect(result.current.status).toBe(status === 'pending' ? 'dirty' : 'unavailable')
    expect(result.current.canSave).toBe(false)
  })

  it('keeps local text during a remote update and requires explicit resolution', () => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'my title'))
    act(() => result.current.receive({ title: 'remote title', year: 2001 }))
    expect(result.current.values.title).toBe('my title')
    expect(result.current.status).toBe('conflict')
    expect(result.current.beginSave()).toBeNull()
    act(() => result.current.resolveConflict('local'))
    expect(result.current.values).toEqual({ title: 'my title', year: 2001 })
    expect(result.current.canSave).toBe(true)
  })

  it('clears only the submitted edits and retains text typed during a save', () => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'submitted'))
    let submission: ReturnType<typeof result.current.beginSave>
    act(() => { submission = result.current.beginSave() })
    act(() => result.current.updateField('title', 'newer'))
    act(() => result.current.completeSave(submission!))
    expect(result.current.values.title).toBe('newer')
    expect(result.current.dirty.has('title')).toBe(true)
    expect(result.current.canSave).toBe(true)
  })

  it.each(['completion', 'revocation', 'conflict', 'failure'] as const)(
    'keeps a newer edit that reverted to the old baseline through save %s',
    outcome => {
      const { result } = renderDraft()
      act(() => result.current.updateField('title', 'submitted'))
      let submission: ReturnType<typeof result.current.beginSave>
      act(() => { submission = result.current.beginSave() })
      act(() => result.current.updateField('title', 'old'))
      if (outcome === 'revocation') {
        act(() => result.current.revokeAccess())
        act(() => result.current.completeSave(submission!))
        expect(result.current.values).toEqual({ title: 'old' })
        expect(result.current.dirty.has('title')).toBe(true)
        expect(result.current.status).toBe('unavailable')
      } else if (outcome === 'conflict') {
        act(() => result.current.receive({ title: 'remote', year: 2001 }))
        act(() => result.current.completeSave(submission!))
        act(() => result.current.resolveConflict('local'))
        expect(result.current.values).toEqual({ title: 'old', year: 2001 })
        expect(result.current.dirty.has('title')).toBe(true)
        expect(result.current.canSave).toBe(true)
      } else if (outcome === 'failure') {
        act(() => result.current.failSave(submission!))
        expect(result.current.values.title).toBe('old')
        expect(result.current.dirty.has('title')).toBe(false)
        expect(result.current.status).toBe('clean')
      } else {
        act(() => result.current.completeSave(submission!))
        expect(result.current.values.title).toBe('old')
        expect(result.current.dirty.has('title')).toBe(true)
        expect(result.current.canSave).toBe(true)
      }
    },
  )

  it('keeps deleted-record text while disabling saves', () => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'my title'))
    act(() => result.current.receive(null))
    expect(result.current.values).toEqual({ title: 'my title', year: 2000 })
    expect(result.current.status).toBe('unavailable')
    expect(result.current.beginSave()).toBeNull()
  })

  it('purges server fields on access revocation and ignores the revoked submission', () => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'my title'))
    let submission: ReturnType<typeof result.current.beginSave>
    act(() => { submission = result.current.beginSave() })
    act(() => result.current.revokeAccess())
    act(() => result.current.completeSave(submission!))
    expect(result.current.values).toEqual({ title: 'my title' })
    expect(result.current.status).toBe('unavailable')
    expect(result.current.pending).toBeNull()
  })

  it('ignores repeated old server snapshots after a successful save', () => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'submitted'))
    let submission: ReturnType<typeof result.current.beginSave>
    act(() => { submission = result.current.beginSave() })
    act(() => result.current.completeSave(submission!))
    act(() => result.current.receive({ title: 'old', year: 2000 }))
    expect(result.current.values.title).toBe('submitted')
    expect(result.current.status).toBe('clean')
  })

  it('compares object keys by value without treating key order as a remote change', () => {
    const { result } = renderDraft()
    act(() => result.current.updateField('title', 'mine'))
    act(() => result.current.receive({ year: 2000, title: 'old' }))
    expect(result.current.status).toBe('dirty')
  })
})
