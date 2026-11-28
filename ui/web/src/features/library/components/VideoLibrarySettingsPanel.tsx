import { useState } from 'react'
import { ArrowDown, ArrowUp } from 'lucide-react'
import styled from 'styled-components'
import { useIsAdmin } from '@/features/auth/hooks/use-admin-check'
import { useMetadataProviders } from '@/features/admin/hooks/use-metadata-admin'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { Button } from '@/shared/components/ui/button'
import { Checkbox } from '@/shared/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { textRole } from '@/shared/theme'
import { formatMetadataProvider } from '@/shared/utils/format-metadata-provider'
import type { LibraryType } from '../api/library-api'
import { useVideoLibrarySettings } from '../hooks/use-video-library-settings'

const Section = styled.section`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const Heading = styled.h2`
  margin: 0;
  ${textRole('label')}
`

const Text = styled.p`
  margin: 0;
  ${textRole('body-sm')}
`

const Hint = styled(Text)`
  color: var(--color-muted-foreground);
`

const ProviderList = styled.ol`
  list-style: decimal;
  padding-left: 1.5rem;
  margin: 0;
`

const Provider = styled.li`
  padding: 0.5rem;
  border-bottom: 1px solid var(--color-border);
  ${textRole('body-sm')}
`

const Row = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem;
`

const Controls = styled.div`
  display: flex;
  gap: 0.5rem;
`

const Label = styled.label`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  ${textRole('body-sm')}
`

const ErrorText = styled(Text)`
  color: var(--color-destructive);
`

const DialogBody = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

type Props = {
  libraryId: string
  libraryType: LibraryType
}

export function VideoLibrarySettingsPanel({ libraryId, libraryType }: Props) {
  const isAdmin = useIsAdmin()
  if (!isAdmin || (libraryType !== 'movie' && libraryType !== 'tv_show')) {
    return null
  }

  return <VideoLibrarySettings key={libraryId} libraryId={libraryId} />
}

function VideoLibrarySettings({ libraryId }: { libraryId: string }) {
  const [previewOpen, setPreviewOpen] = useState(false)
  const [draft, setDraft] = useState<string[] | null>(null)
  const [force, setForce] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const { order, preview, saveOrder, rematch } = useVideoLibrarySettings(libraryId, previewOpen)
  const providers = useMetadataProviders()
  const currentOrder = draft ?? order.data?.providers ?? []
  const changed = draft !== null && draft.join(',') !== order.data?.providers.join(',')
  const busy = saveOrder.isPending || rematch.isPending

  function move(index: number, direction: number) {
    const reordered = [...currentOrder]
    const destination = index + direction
    if (destination < 0 || destination >= reordered.length) {
      return
    }
    const moved = reordered[index]
    reordered[index] = reordered[destination]
    reordered[destination] = moved
    setDraft(reordered)
    setNotice(null)
    saveOrder.reset()
  }

  function openPreview() {
    setNotice(null)
    setForce(false)
    rematch.reset()
    setPreviewOpen(true)
  }

  function providerStatus(id: string) {
    if (id === 'local') {
      return 'Available'
    }
    const provider = providers.data?.find((entry) => entry.id === id)
    if (!provider) {
      return providers.isPending ? 'Loading status…' : 'Status unavailable'
    }
    if (!provider.enabled) {
      return 'Disabled on this server'
    }
    if (!provider.configured) {
      return 'Key not configured'
    }
    return 'Available'
  }

  return (
    <Section aria-labelledby="video-library-matching-heading">
      <Heading id="video-library-matching-heading">Video matching</Heading>
      <Hint>Providers are tried in this order. Disabled providers and providers without a key are skipped.</Hint>
      {order.isPending && <Text role="status">Loading provider order…</Text>}
      {order.isError && (
        <div role="alert">
          <ErrorText>{parseApiError(order.error, 'Could not load the provider order.').message}</ErrorText>
          <Button variant="outline" onClick={() => order.refetch()}>Retry provider order</Button>
        </div>
      )}
      {order.data && (
        <>
          <ProviderList aria-label="Metadata provider order">
            {currentOrder.map((provider, index) => {
              const label = formatMetadataProvider(provider)

              return (
                <Provider key={provider}>
                  <Row>
                    <div>
                      <Text>{label}</Text>
                      <Hint>{providerStatus(provider)}</Hint>
                    </div>
                    <Controls>
                      <Button
                        size="sm"
                        variant="outline"
                        aria-label={`Move ${label} up`}
                        disabled={index === 0 || busy}
                        onClick={() => move(index, -1)}
                      >
                        <ArrowUp size={16} aria-hidden />
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        aria-label={`Move ${label} down`}
                        disabled={index === currentOrder.length - 1 || busy}
                        onClick={() => move(index, 1)}
                      >
                        <ArrowDown size={16} aria-hidden />
                      </Button>
                    </Controls>
                  </Row>
                </Provider>
              )
            })}
          </ProviderList>
          <Controls>
            <Button
              disabled={!changed || busy}
              onClick={() => saveOrder.mutate(currentOrder, {
                onSuccess: () => {
                  setDraft(null)
                  setNotice('Provider order saved.')
                },
              })}
            >
              {saveOrder.isPending ? 'Saving…' : 'Save provider order'}
            </Button>
            <Button variant="outline" disabled={!changed || busy} onClick={() => {
              setDraft(null)
              saveOrder.reset()
            }}>
              Reset changes
            </Button>
          </Controls>
        </>
      )}
      {saveOrder.isError && <ErrorText role="alert">{parseApiError(saveOrder.error, 'Could not save the provider order.').message}</ErrorText>}
      {providers.isError && <Hint role="status">Provider status is unavailable. The saved order still includes every provider.</Hint>}
      {notice && <Text role="status">{notice}</Text>}
      <div>
        <Button variant="outline" disabled={busy || changed} onClick={openPreview}>Review library re-match</Button>
      </div>
      {changed && <Hint>Save or reset the provider order before re-matching.</Hint>}
      <Dialog open={previewOpen} onOpenChange={(open) => {
        if (!rematch.isPending) {
          setPreviewOpen(open)
        }
      }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Re-match this library?</DialogTitle>
            <DialogDescription>Review the affected titles before requesting new matches.</DialogDescription>
          </DialogHeader>
          <DialogBody>
            {preview.isFetching && <Text role="status">Loading re-match preview…</Text>}
            {preview.isError && (
              <div role="alert">
                <ErrorText>{parseApiError(preview.error, 'Could not load the re-match preview.').message}</ErrorText>
                <Button variant="outline" onClick={() => preview.refetch()}>Retry preview</Button>
              </div>
            )}
            {preview.data && !preview.isFetching && (
              <>
                <Text>{preview.data.totalTitles} titles, including {preview.data.handMatchedTitles} manually matched titles.</Text>
                <Text>{preview.data.handAssignedFiles} files have manual assignments. Re-matched titles return their file assignments to review.</Text>
                <Label>
                  <Checkbox
                    checked={force}
                    disabled={rematch.isPending}
                    onCheckedChange={(checked) => {
                      setForce(checked === true)
                      rematch.reset()
                    }}
                  />
                  Include manually matched titles
                </Label>
                <Hint>Manual title matches are kept unless this option is selected. Locked metadata fields remain locked.</Hint>
              </>
            )}
            {rematch.isError && <ErrorText role="alert">{parseApiError(rematch.error, 'Could not request the library re-match.').message}</ErrorText>}
            <Controls>
              <Button variant="outline" disabled={rematch.isPending} onClick={() => setPreviewOpen(false)}>Cancel</Button>
              <Button
                disabled={!preview.data || preview.isFetching || preview.isError || rematch.isPending}
                onClick={() => rematch.mutate({ confirmed: true, force }, {
                  onSuccess: (result) => {
                    setPreviewOpen(false)
                    setNotice(`Re-match queued for ${result.queued ?? 0} titles; ${result.skipped ?? 0} titles kept.`)
                  },
                })}
              >
                {rematch.isPending ? 'Requesting…' : 'Confirm library re-match'}
              </Button>
            </Controls>
          </DialogBody>
        </DialogContent>
      </Dialog>
    </Section>
  )
}
