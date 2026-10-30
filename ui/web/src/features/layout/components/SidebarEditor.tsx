import { useDraft } from '@/shared/drafts/use-draft'
import { useLiveCoordinator } from '@/shared/live/context'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { useShallow } from 'zustand/react/shallow'
import styled from 'styled-components'
import { useState, useEffect, useRef } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useSidebarStore, type SidebarItemData } from '../stores/sidebar-store'
import { useMediaModeStore, type MediaType } from '../stores/media-mode-store'
import { type MediaSidebarSchema, type SidebarSection } from '../schemas/types'
import { getSidebarIcon, SIDEBAR_ICONS } from '../schemas/icons'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { ChevronDown, ChevronRight, Plus, Trash2, X } from 'lucide-react'

const ICON_OPTIONS = Object.keys(SIDEBAR_ICONS).map((key) => ({
  value: key,
  label: key,
}))

const TYPE_OPTIONS = [
  { value: 'page_link', label: 'Page Link' },
  { value: 'smart_filter', label: 'Smart Filter' },
  { value: 'panel_action', label: 'Panel Action' },
] as const

const EditorSkeleton = styled(Skeleton)`
  height: 2.5rem;
  width: 100%;
  border-radius: var(--radius-md);
`

const SmallInput = styled(Input)`
  height: 1.75rem;
  font-size: 0.75rem;
`

const SmallSelectTrigger = styled(SelectTrigger)`
  height: 1.75rem;
  flex: 1;
  font-size: 0.75rem;
`

const MutedButton = styled(Button)`
  color: var(--color-muted-foreground);
`

const StretchButton = styled(Button)`
  flex: 1;
`

const Overlay = styled.div`
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
`

const Backdrop = styled.div`
  position: absolute;
  inset: 0;
  background-color: color-mix(in srgb, #000 40%, transparent);
  backdrop-filter: blur(4px);
`

const Sheet = styled.div`
  position: relative;
  margin-left: 14rem;
  display: flex;
  height: 100%;
  width: 20rem;
  flex-direction: column;
  background-color: var(--color-card);
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
`

const SheetHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
`

const SheetTitle = styled.h2`
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.025em;
  margin: 0;
`

const SheetSubtitle = styled.p`
  font-size: 11px;
  color: var(--color-muted-foreground);
  text-transform: capitalize;
  margin: 0;
`

const CloseButton = styled.button`
  border-radius: var(--radius-md);
  padding: 0.25rem;
  color: var(--color-muted-foreground);
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    background-color: color-mix(in srgb, var(--color-highlight) 50%, transparent);
    color: var(--color-highlight-foreground);
  }
`

const SheetContent = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 1rem;
`

const SectionList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const SectionCard = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
`

const SectionHeader = styled.button`
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  text-align: left;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    color: var(--color-foreground);
  }
`

const SectionItemCount = styled.span`
  margin-left: auto;
  font-size: 10px;
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
`

const SectionItems = styled.div`
  padding: 0 0.5rem 0.5rem;

  & > * + * {
    margin-top: 0.125rem;
  }
`

const ItemRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  background-color: color-mix(in srgb, var(--color-secondary) 50%, transparent);
  padding: 0.375rem 0.5rem;
`

const ItemIconWrapper = styled.span`
  flex-shrink: 0;
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
  display: inline-flex;

  & > svg {
    width: 0.75rem;
    height: 0.75rem;
  }
`

const ItemLabel = styled.span`
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const ItemType = styled.span`
  font-size: 10px;
  color: var(--color-muted-foreground);
`

const MoveButton = styled.button`
  color: color-mix(in srgb, var(--color-muted-foreground) 40%, transparent);
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  display: inline-flex;

  &:hover {
    color: var(--color-foreground);
  }

  &:disabled {
    opacity: 0.2;
    cursor: default;
  }
`

const DeleteButton = styled.button`
  color: color-mix(in srgb, var(--color-muted-foreground) 40%, transparent);
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  display: inline-flex;

  &:hover {
    color: var(--color-destructive);
  }
`

const AddFormWrapper = styled.div`
  margin-top: 0.25rem;
  border-radius: var(--radius-md);
  background-color: color-mix(in srgb, var(--color-secondary) 30%, transparent);
  padding: 0.5rem;

  & > * + * {
    margin-top: 0.5rem;
  }
`

const AddFormRow = styled.div`
  display: flex;
  gap: 0.5rem;
`

const AddFormActions = styled.div`
  display: flex;
  gap: 0.5rem;
`

const AddItemButton = styled.button`
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.25rem;
  border-radius: var(--radius-md);
  padding: 0.25rem 0.5rem;
  font-size: 11px;
  color: color-mix(in srgb, var(--color-muted-foreground) 60%, transparent);
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    color: var(--color-foreground);
  }
`

const LoadingSkeletons = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const SheetFooter = styled.div`
  display: flex;
  gap: 0.5rem;
  border-top: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
`

export function SidebarEditor() {
  const isEditorOpen = useSidebarStore((state) => state.isEditorOpen)
  const activeMedia = useMediaModeStore((state) => state.activeMedia)
  const schema = useSidebarStore((state) => state.schemas[activeMedia])
  if (!isEditorOpen) return null
  return <SidebarEditorForm key={activeMedia} activeMedia={activeMedia} initialSchema={schema} />
}

function SidebarEditorForm({ activeMedia, initialSchema }: { activeMedia: MediaType; initialSchema: MediaSidebarSchema }) {
  const { isLoading, setEditorOpen, setSchema } = useSidebarStore(useShallow((state) => ({ isLoading: state.isLoading, setEditorOpen: state.setEditorOpen, setSchema: state.setSchema })))
  const coordinator = useLiveCoordinator()
  const unavailable = useSidebarStore(state => state.error === 'Sidebar access unavailable')
  const draft = useDraft({ identity: `sidebar:${activeMedia}`, initialData: { sections: initialSchema.sections } })
  const editingSections = draft.values.sections ?? []
  const editRevision = useRef(0)
  const setEditingSections = (update: SidebarSection[] | ((sections: SidebarSection[]) => SidebarSection[])) => {
    editRevision.current++
    draft.updateField('sections', typeof update === 'function' ? update(editingSections) : update)
  }
  const receive = draft.receive
  const revokeAccess = draft.revokeAccess
  useEffect(() => {
    if (unavailable) revokeAccess()
    else receive({ sections: initialSchema.sections })
  }, [initialSchema.sections, receive, revokeAccess, unavailable])
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])
  const [isSaving, setIsSaving] = useState(false)
  const [collapsedSections, setCollapsedSections] = useState<Set<string>>(new Set())
  const [showAddForm, setShowAddForm] = useState<string | null>(null) // section id
  const [newLabel, setNewLabel] = useState('')
  const [newIcon, setNewIcon] = useState('home')
  const [newType, setNewType] = useState<string>('page_link')
  const [newRoute, setNewRoute] = useState('/')

  const toggleCollapse = (sectionId: string) => {
    setCollapsedSections((prev) => {
      const next = new Set(prev)
      if (next.has(sectionId)) {
        next.delete(sectionId)
      } else {
        next.add(sectionId)
      }
      return next
    })
  }

  const handleDeleteItem = (sectionId: string, itemIndex: number) => {
    setEditingSections((prev) =>
      prev.map((s) =>
        s.id === sectionId
          ? { ...s, items: s.items.filter((_, i) => i !== itemIndex) }
          : s,
      ),
    )
  }

  const handleMoveUp = (sectionId: string, itemIndex: number) => {
    if (itemIndex === 0) return
    setEditingSections((prev) =>
      prev.map((s) => {
        if (s.id !== sectionId) return s
        const next = [...s.items]
        ;[next[itemIndex - 1], next[itemIndex]] = [next[itemIndex], next[itemIndex - 1]]
        return { ...s, items: next }
      }),
    )
  }

  const handleMoveDown = (sectionId: string, itemIndex: number) => {
    const section = editingSections.find((s) => s.id === sectionId)
    if (!section || itemIndex === section.items.length - 1) return
    setEditingSections((prev) =>
      prev.map((s) => {
        if (s.id !== sectionId) return s
        const next = [...s.items]
        ;[next[itemIndex], next[itemIndex + 1]] = [next[itemIndex + 1], next[itemIndex]]
        return { ...s, items: next }
      }),
    )
  }

  const handleAddItem = (sectionId: string) => {
    if (!newLabel.trim()) return
    const config: Record<string, unknown> = {}
    if (newType === 'page_link') config.route = newRoute
    if (newType === 'panel_action') config.tab = 'queue'

    const item: SidebarItemData = {
      id: `${newType}-${crypto.randomUUID()}`,
      type: newType as SidebarItemData['type'],
      label: newLabel.trim(),
      icon: newIcon,
      config,
    }

    setEditingSections((prev) =>
      prev.map((s) =>
        s.id === sectionId
          ? { ...s, items: [...s.items, item] }
          : s,
      ),
    )
    setShowAddForm(null)
    setNewLabel('')
    setNewIcon('home')
    setNewType('page_link')
    setNewRoute('/')
  }

  const handleSave = async () => {
    const submission = draft.beginSave()
    if (!submission) return
    const owner = captureOwnership()
    const revision = editRevision.current
    const release = coordinator?.beginMutation(['preferences'])
    setIsSaving(true)
    try {
      const res = await AXIOS_INSTANCE.put(`/api/user/sidebar-config/${activeMedia}`, submission.values)
      if (!mounted.current || !isCurrentOwnership(owner)) return
      draft.completeSave(submission)
      if (res.data?.mediaType) setSchema(activeMedia, res.data)
      if (revision === editRevision.current) setEditorOpen(false)
    } catch {
      if (!mounted.current || !isCurrentOwnership(owner)) return
      draft.failSave(submission)
    } finally {
      release?.()
      if (mounted.current && isCurrentOwnership(owner)) setIsSaving(false)
    }
  }

  const handleReset = async () => {
    const owner = captureOwnership()
    const revision = editRevision.current
    const release = coordinator?.beginMutation(['preferences'])
    setIsSaving(true)
    try {
      const res = await AXIOS_INSTANCE.delete(`/api/user/sidebar-config/${activeMedia}`)
      if (!mounted.current || !isCurrentOwnership(owner)) return
      if (res.data?.mediaType) setSchema(activeMedia, res.data)
      if (revision === editRevision.current) {
        draft.receive({ sections: res.data?.sections ?? [] })
        draft.resolveConflict('server')
      }
    } catch {
      return
    } finally {
      release?.()
      if (mounted.current && isCurrentOwnership(owner)) setIsSaving(false)
    }
  }

  return (
    <Overlay>
      {/* Backdrop */}
      <Backdrop onClick={() => setEditorOpen(false)} />

      {/* Sheet */}
      <Sheet>
        {/* Header */}
        <SheetHeader>
          <div>
            <SheetTitle>Customize Sidebar</SheetTitle>
            <SheetSubtitle>{activeMedia}</SheetSubtitle>
          </div>
          <CloseButton
            type="button"
            onClick={() => setEditorOpen(false)}
          >
            <X size={14} />
          </CloseButton>
        </SheetHeader>

        {draft.status === 'conflict' && <div role="alert">
          The sidebar changed elsewhere. Choose which version to keep.
          <Button onClick={() => draft.resolveConflict('local')}>Keep my edits</Button>
          <Button onClick={() => draft.resolveConflict('server')}>Use server version</Button>
        </div>}
        {draft.status === 'unavailable' && <div role="alert">This sidebar is unavailable. Your edits are preserved.</div>}
        {/* Content */}
        <SheetContent>
          {isLoading ? (
            <LoadingSkeletons>
              {Array.from({ length: 6 }).map((_, i) => (
                <EditorSkeleton key={i} />
              ))}
            </LoadingSkeletons>
          ) : (
            <SectionList>
              {editingSections.map((section) => {
                const isCollapsed = collapsedSections.has(section.id)
                return (
                  <SectionCard key={section.id}>
                    {/* Section header */}
                    <SectionHeader
                      type="button"
                      onClick={() => toggleCollapse(section.id)}
                    >
                      {isCollapsed ? <ChevronRight size={12} /> : <ChevronDown size={12} />}
                      {section.label}
                      <SectionItemCount>{section.items.length}</SectionItemCount>
                    </SectionHeader>

                    {/* Section items */}
                    {!isCollapsed && (
                      <SectionItems>
                        {section.items.map((item, index) => {
                          const ItemIcon = getSidebarIcon(item.icon)
                          return (
                            <ItemRow key={item.id}>
                              <ItemIconWrapper><ItemIcon size={12} /></ItemIconWrapper>
                              <ItemLabel>{item.label}</ItemLabel>
                              <ItemType>
                                {item.type === 'page_link' ? 'Link' : item.type === 'smart_filter' ? 'Smart' : 'Action'}
                              </ItemType>
                              <MoveButton
                                type="button"
                                onClick={() => handleMoveUp(section.id, index)}
                                disabled={index === 0}
                                aria-label="Move up"
                              >
                                <svg width={12} height={12} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="m18 15-6-6-6 6" /></svg>
                              </MoveButton>
                              <MoveButton
                                type="button"
                                onClick={() => handleMoveDown(section.id, index)}
                                disabled={index === section.items.length - 1}
                                aria-label="Move down"
                              >
                                <svg width={12} height={12} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="m6 9 6 6 6-6" /></svg>
                              </MoveButton>
                              <DeleteButton
                                type="button"
                                onClick={() => handleDeleteItem(section.id, index)}
                                aria-label="Remove item"
                              >
                                <Trash2 size={12} />
                              </DeleteButton>
                            </ItemRow>
                          )
                        })}

                        {/* Add to this section */}
                        {showAddForm === section.id ? (
                          <AddFormWrapper>
                            <SmallInput
                              placeholder="Label"
                              value={newLabel}
                              onChange={(e) => setNewLabel(e.target.value)}
                              autoFocus
                            />
                            <AddFormRow>
                              <Select value={newType} onValueChange={setNewType}>
                                <SmallSelectTrigger>
                                  <SelectValue />
                                </SmallSelectTrigger>
                                <SelectContent>
                                  {TYPE_OPTIONS.map((opt) => (
                                    <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                              <Select value={newIcon} onValueChange={setNewIcon}>
                                <SmallSelectTrigger>
                                  <SelectValue />
                                </SmallSelectTrigger>
                                <SelectContent>
                                  {ICON_OPTIONS.map((opt) => (
                                    <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </AddFormRow>
                            {newType === 'page_link' && (
                              <SmallInput
                                placeholder="Route (e.g. /albums)"
                                value={newRoute}
                                onChange={(e) => setNewRoute(e.target.value)}
                              />
                            )}
                            <AddFormActions>
                              <Button size="sm" onClick={() => handleAddItem(section.id)} disabled={!newLabel.trim()}>
                                Add
                              </Button>
                              <Button size="sm" variant="ghost" onClick={() => setShowAddForm(null)}>
                                Cancel
                              </Button>
                            </AddFormActions>
                          </AddFormWrapper>
                        ) : (
                          <AddItemButton
                            type="button"
                            onClick={() => setShowAddForm(section.id)}
                          >
                            <Plus size={10} />
                            Add item
                          </AddItemButton>
                        )}
                      </SectionItems>
                    )}
                  </SectionCard>
                )
              })}
            </SectionList>
          )}
        </SheetContent>

        {/* Footer */}
        <SheetFooter>
          <MutedButton size="sm" variant="ghost" onClick={handleReset} disabled={isSaving || draft.status === 'unavailable'}>
            Reset
          </MutedButton>
          <StretchButton size="sm" onClick={handleSave} disabled={isSaving || !draft.canSave}>
            {isSaving ? 'Saving...' : 'Save changes'}
          </StretchButton>
        </SheetFooter>
      </Sheet>
    </Overlay>
  )
}
