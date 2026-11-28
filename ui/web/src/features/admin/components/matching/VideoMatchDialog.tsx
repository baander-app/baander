import { useId, useRef, useState, type FormEvent } from 'react'
import { isAxiosError } from 'axios'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { formatMetadataProvider } from '@/shared/utils/format-metadata-provider'
import { ContentText } from '@/shared/typography'
import { Button } from '@/shared/components/ui/button'
import { Checkbox } from '@/shared/components/ui/checkbox'
import { Input } from '@/shared/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select'
import {
  AssignVideoFileRequestExtraKind,
  type VideoMatchChoicesResourceFilesItemsItem,
  type VideoMatchReviewPageResourceItemsItem,
} from '@/shared/api-client/gen/endpoints'
import {
  useApplyVideoMatch,
  useAssignVideoFile,
  useSetVideoOrdering,
  useVideoMatchCandidates,
  useVideoMatchChoices,
} from '../../hooks/use-video-match-admin'

const Stack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
`

const Row = styled.div`
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
`

const SearchForm = styled.form`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const Label = styled.label`
  ${textRole('body-sm')}
`

const Message = styled.p`
  ${textRole('body-sm')}
  color: var(--color-muted-foreground);
`

const Failure = styled.p`
  ${textRole('body-sm')}
  color: var(--color-destructive);
`

const Heading = styled.h3`
  ${textRole('body')}
`

const List = styled.ul`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0;
  list-style: none;
`

const Item = styled.li`
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.5rem;
  border-bottom: 1px solid var(--color-border);
  padding: 0.5rem 0;
`

const Path = styled.span`
  ${textRole('mono-sm')}
  overflow-wrap: anywhere;
  min-width: 0;
`

const CheckLabel = styled.label`
  ${textRole('body-sm')}
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const PROBLEMS: Record<string, string> = {
  disabled: 'This provider is switched off.',
  not_configured: 'This provider has no configured key.',
  unavailable: 'This provider is unavailable. Try again later.',
  login_failed: 'The provider could not authenticate. Check its configured key.',
  refused: 'The provider refused this request.',
  unmatched: 'Match this series before choosing an ordering.',
  not_found: 'The saved entry no longer exists at this provider.',
}

function problemMessage(problem: string): string {
  return PROBLEMS[problem] ?? `The provider could not complete the request (${problem}).`
}

function errorMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const body: unknown = error.response?.data
    if (typeof body === 'object' && body !== null && 'error' in body) {
      const detail = body.error
      if (typeof detail === 'object' && detail !== null && 'message' in detail && typeof detail.message === 'string') {
        return detail.message
      }
    }
  }
  return fallback
}

interface VideoMatchDialogProps {
  review: VideoMatchReviewPageResourceItemsItem
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function VideoMatchDialog({ review, open, onOpenChange }: VideoMatchDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="wide" onCloseAutoFocus={(event) => event.preventDefault()}>
        <DialogHeader>
          <DialogTitle>Review video match</DialogTitle>
          <DialogDescription>
            <ContentText textRole="body" text={review.title} />
          </DialogDescription>
          {review.path && <Path>{review.path}</Path>}
        </DialogHeader>
        {open && (
          <MatchSession
            key={`${review.ownerKind}:${review.ownerPublicId}:${review.publicId}`}
            review={review}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function MatchSession({ review }: { review: VideoMatchReviewPageResourceItemsItem }) {
  const [step, setStep] = useState(review.kind === 'file' ? 'files' : 'search')
  const [notice, setNotice] = useState<string | null>(null)
  const apply = useApplyVideoMatch()
  const ordering = useSetVideoOrdering()
  const assignment = useAssignVideoFile()
  const busy = apply.isPending || ordering.isPending || assignment.isPending
  return (
    <Stack>
      {notice && <Message role="status">{notice}</Message>}
      <Tabs value={step} onValueChange={(value) => {
        if (!busy) {
          setStep(value)
          setNotice(null)
        }
      }}>
        <TabsList aria-label="Video match steps">
          <TabsTrigger value="search" disabled={busy}>Search</TabsTrigger>
          {review.ownerKind === 'series' && <TabsTrigger value="ordering" disabled={busy}>Ordering</TabsTrigger>}
          <TabsTrigger value="files" disabled={busy}>Files</TabsTrigger>
        </TabsList>
        <TabsContent value="search">
          <SearchStep
            review={review}
            apply={apply}
            onApplied={() => {
              setNotice(review.ownerKind === 'series'
                ? 'Match applied. Review its ordering and file assignments.'
                : 'Match applied. Review its file assignments.')
              setStep(review.ownerKind === 'series' ? 'ordering' : 'files')
            }}
          />
        </TabsContent>
        {review.ownerKind === 'series' && (
          <TabsContent value="ordering">
            <OrderingStep review={review} update={ordering} />
          </TabsContent>
        )}
        <TabsContent value="files">
          <FilesStep review={review} assign={assignment} />
        </TabsContent>
      </Tabs>
    </Stack>
  )
}

interface SearchStepProps {
  review: VideoMatchReviewPageResourceItemsItem
  apply: ReturnType<typeof useApplyVideoMatch>
  onApplied: () => void
}

function SearchStep({ review, apply, onApplied }: SearchStepProps) {
  const searchId = useId()
  const [query, setQuery] = useState(review.title)
  const [submitted, setSubmitted] = useState<string | null>(null)
  const candidates = useVideoMatchCandidates(review.ownerKind, review.ownerPublicId, submitted ?? '', submitted !== null)
  const [failure, setFailure] = useState<string | null>(null)
  const search = (event: FormEvent) => {
    event.preventDefault()
    if (query.trim() === '' || apply.isPending || candidates.isFetching) return
    setFailure(null)
    if (submitted === query.trim()) {
      candidates.refetch()
    } else {
      setSubmitted(query.trim())
    }
  }

  return (
    <Stack>
      <SearchForm onSubmit={search}>
        <Label htmlFor={searchId}>Search title</Label>
        <Row>
          <Input
            id={searchId}
            value={query}
            maxLength={200}
            onChange={(event) => setQuery(event.target.value)}
            disabled={apply.isPending}
          />
          <Button type="submit" disabled={apply.isPending || candidates.isFetching || query.trim() === ''}>
            Search
          </Button>
        </Row>
      </SearchForm>
      <Message>
        {review.ownerKind === 'series'
          ? 'Applying a match resets the episode ordering and returns hand-assigned files to review.'
          : 'Applying a match returns hand-assigned files to review.'}
      </Message>
      {candidates.isFetching && <Message role="status">Searching providers…</Message>}
      {candidates.isError && <Failure role="alert">{errorMessage(candidates.error, 'The search failed. Try again.')}</Failure>}
      {failure && <Failure role="alert">{failure}</Failure>}
      {candidates.data && candidates.data.providers.length === 0 && <Message>No configured provider is enabled for this title.</Message>}
      {candidates.data?.providers.map((provider) => {
        const label = formatMetadataProvider(provider.provider)

        return (
          <Stack key={provider.provider}>
            <Heading>{label}</Heading>
            {provider.problem !== null ? (
              <Failure role="alert">{problemMessage(provider.problem)}</Failure>
            ) : provider.candidates.length === 0 ? (
              <Message>No matching titles found.</Message>
            ) : (
              <List aria-label={`${label} candidates`} aria-busy={apply.isPending}>
                {provider.candidates.map((candidate) => (
                  <Item key={candidate.id}>
                    <span>
                      <ContentText textRole="body-sm" text={candidate.title} />
                      {candidate.year !== null && ` (${candidate.year})`}
                    </span>
                    <Button
                      size="sm"
                      disabled={apply.isPending || candidates.isFetching}
                      aria-label={`Apply ${label} match ${candidate.title}`}
                      onClick={() => {
                        setFailure(null)
                        apply.mutateAsync({
                          kind: review.ownerKind,
                          publicId: review.ownerPublicId,
                          provider: provider.provider,
                          candidateId: candidate.id,
                        })
                          .then(onApplied)
                          .catch((error: unknown) => setFailure(errorMessage(error, 'The match could not be applied. Try again.')))
                      }}
                    >
                      {apply.isPending ? 'Applying…' : 'Apply match'}
                    </Button>
                  </Item>
                ))}
              </List>
            )}
          </Stack>
        )
      })}
    </Stack>
  )
}

interface OrderingStepProps {
  review: VideoMatchReviewPageResourceItemsItem
  update: ReturnType<typeof useSetVideoOrdering>
}

function OrderingStep({ review, update }: OrderingStepProps) {
  const choices = useVideoMatchChoices(review.ownerKind, review.ownerPublicId, 'orderings')
  const [selected, setSelected] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const ordering = selected ?? choices.data?.currentOrdering ?? ''
  const offered = choices.data?.orderings.some((option) => option.id === ordering) ?? false
  const labelId = useId()

  return (
    <Stack>
      <Message>Changing the ordering places scanner-assigned files again and preserves hand-assigned files.</Message>
      {choices.isPending ? <Message role="status">Loading orderings…</Message> : choices.isError ? (
        <Failure role="alert">{errorMessage(choices.error, 'Orderings could not be loaded.')}</Failure>
      ) : choices.data?.orderingProblem ? (
        <Failure role="alert">{problemMessage(choices.data.orderingProblem)}</Failure>
      ) : (
        <>
          <Label id={labelId}>Episode ordering</Label>
          <Select
            value={ordering}
            disabled={update.isPending || choices.isFetching}
            onValueChange={(value) => {
              setSelected(value)
              setSaved(false)
            }}
          >
            <SelectTrigger aria-labelledby={labelId}><SelectValue placeholder="Choose an ordering" /></SelectTrigger>
            <SelectContent>
              {choices.data?.orderings.map((option) => (
                <SelectItem key={option.id} value={option.id}>{option.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {!offered && choices.data?.currentOrdering && (
            <Message>The saved ordering is no longer offered. Choose an available ordering to replace it.</Message>
          )}
          <Button
            disabled={!offered || update.isPending || choices.isFetching}
            onClick={() => {
              setFailure(null)
              setSaved(false)
              update.mutateAsync({ publicId: review.ownerPublicId, ordering })
                .then(() => setSaved(true))
                .catch((error: unknown) => setFailure(errorMessage(error, 'The ordering could not be saved. Try again.')))
            }}
          >
            {update.isPending ? 'Saving…' : 'Save ordering'}
          </Button>
        </>
      )}
      {(choices.isError || choices.data?.orderingProblem) && (
        <Button variant="outline" onClick={() => choices.refetch()} disabled={choices.isFetching}>Retry orderings</Button>
      )}
      {failure && <Failure role="alert">{failure}</Failure>}
      {saved && <Message role="status">Ordering saved.</Message>}
    </Stack>
  )
}

interface FilesStepProps {
  review: VideoMatchReviewPageResourceItemsItem
  assign: ReturnType<typeof useAssignVideoFile>
}

function FilesStep({ review, assign }: FilesStepProps) {
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<VideoMatchChoicesResourceFilesItemsItem | null>(null)
  const [saved, setSaved] = useState(false)
  const selectedTrigger = useRef<HTMLButtonElement>(null)
  const files = useVideoMatchChoices(review.ownerKind, review.ownerPublicId, 'files', page)
  const initialFile = review.kind === 'file' ? review.publicId : null
  const closeAssignment = () => {
    selectedTrigger.current?.focus()
    setSelected(null)
  }

  return (
    <Stack>
      {saved && <Message role="status">File assignment saved.</Message>}
      {files.isPending ? <Message role="status">Loading files…</Message> : files.isError ? (
        <>
          <Failure role="alert">{errorMessage(files.error, 'Files could not be loaded.')}</Failure>
          <Button variant="outline" onClick={() => files.refetch()} disabled={files.isFetching}>Retry files</Button>
        </>
      ) : (
        <>
          {files.data?.files?.items.length === 0 && <Message>No files belong to this title.</Message>}
          <List aria-label="Title files" aria-busy={files.isFetching}>
            {files.data?.files?.items.map((file) => (
              <Item key={file.publicId}>
                <Path>{file.path}</Path>
                <Button
                  ref={file.publicId === selected?.publicId ? selectedTrigger : undefined}
                  size="sm"
                  variant="outline"
                  disabled={assign.isPending}
                  aria-label={`Assign ${file.path}`}
                  onClick={() => {
                    setSaved(false)
                    setSelected(file)
                  }}
                >
                  {file.publicId === (selected?.publicId ?? initialFile) ? 'Edit assignment' : 'Assign'}
                </Button>
              </Item>
            ))}
          </List>
          <Pagination
            page={page}
            hasNext={files.data?.files?.hasNextPage ?? false}
            busy={files.isFetching || assign.isPending}
            onPage={setPage}
            noun="files"
          />
        </>
      )}
      {selected && (
        <FileAssignment
          key={selected.publicId}
          review={review}
          file={selected}
          assign={assign}
          onCancel={closeAssignment}
          onSaved={() => {
            setSaved(true)
            closeAssignment()
          }}
        />
      )}
    </Stack>
  )
}

interface FileAssignmentProps {
  review: VideoMatchReviewPageResourceItemsItem
  file: VideoMatchChoicesResourceFilesItemsItem
  assign: ReturnType<typeof useAssignVideoFile>
  onCancel: () => void
  onSaved: () => void
}

function FileAssignment({ review, file, assign, onCancel, onSaved }: FileAssignmentProps) {
  const [page, setPage] = useState(1)
  const [episodes, setEpisodes] = useState(file.episodePublicIds)
  const [extra, setExtra] = useState<NonNullable<AssignVideoFileRequestExtraKind> | 'episodes' | ''>(
    file.extraKind ?? (review.ownerKind === 'series' ? 'episodes' : ''),
  )
  const [failure, setFailure] = useState<string | null>(null)
  const choices = useVideoMatchChoices(review.ownerKind, review.ownerPublicId, 'episodes', page, review.ownerKind === 'series')
  const labelId = useId()
  const isSeries = review.ownerKind === 'series'

  return (
    <Stack>
      <Heading>Assign <Path>{file.path}</Path></Heading>
      <Label id={labelId}>Assign as</Label>
      <Select
        value={extra}
        disabled={assign.isPending}
        onValueChange={(value) => {
          if (value === 'episodes' || isExtraKind(value)) setExtra(value)
        }}
      >
        <SelectTrigger aria-labelledby={labelId}><SelectValue placeholder="Choose an assignment" /></SelectTrigger>
        <SelectContent>
          {isSeries && <SelectItem value="episodes">Episodes</SelectItem>}
          {Object.values(AssignVideoFileRequestExtraKind).map((kind) => (
            <SelectItem key={kind} value={kind}>{kind.replaceAll('_', ' ')}</SelectItem>
          ))}
        </SelectContent>
      </Select>
      {isSeries && extra === 'episodes' && (
        <>
          <Message>{episodes.length} episodes selected. Selections stay when changing pages.</Message>
          {choices.isPending ? <Message role="status">Loading episodes…</Message> : choices.isError ? (
            <>
              <Failure role="alert">{errorMessage(choices.error, 'Episodes could not be loaded.')}</Failure>
              <Button variant="outline" onClick={() => choices.refetch()} disabled={choices.isFetching}>Retry episodes</Button>
            </>
          ) : (
            <>
              {choices.data?.episodes?.items.length === 0 && <Message>No episodes are available for assignment.</Message>}
              <List aria-label="Episode selection">
                {choices.data?.episodes?.items.map((episode) => (
                  <Item key={episode.publicId}>
                    <CheckLabel>
                      <Checkbox
                        checked={episodes.includes(episode.publicId)}
                        disabled={assign.isPending}
                        onCheckedChange={(checked) => {
                          setEpisodes((current) => checked === true
                            ? [...new Set([...current, episode.publicId])]
                            : current.filter((id) => id !== episode.publicId))
                        }}
                      />
                      <span>
                        {episode.seasonNumber !== null && episode.episodeNumber !== null && `S${episode.seasonNumber} E${episode.episodeNumber} · `}
                        <ContentText textRole="body-sm" text={episode.title} />
                      </span>
                    </CheckLabel>
                  </Item>
                ))}
              </List>
              <Pagination
                page={page}
                hasNext={choices.data?.episodes?.hasNextPage ?? false}
                busy={choices.isFetching || assign.isPending}
                onPage={setPage}
                noun="episodes"
              />
            </>
          )}
        </>
      )}
      {failure && <Failure role="alert">{failure}</Failure>}
      <Row>
        <Button variant="outline" onClick={onCancel} disabled={assign.isPending}>Cancel assignment</Button>
        <Button
          disabled={extra === '' || assign.isPending || (extra === 'episodes'
            && (!isSeries || episodes.length === 0 || choices.isError || choices.isFetching))}
          onClick={() => {
            if (extra === '') return
            setFailure(null)
            const assignment = extra === 'episodes'
              ? { publicId: file.publicId, episodePublicIds: episodes }
              : { publicId: file.publicId, extraKind: extra }
            assign.mutateAsync(assignment)
              .then(onSaved)
              .catch((error: unknown) => setFailure(errorMessage(error, 'The file assignment could not be saved. Try again.')))
          }}
        >
          {assign.isPending ? 'Saving…' : 'Save assignment'}
        </Button>
      </Row>
    </Stack>
  )
}

function isExtraKind(value: string): value is NonNullable<AssignVideoFileRequestExtraKind> {
  return Object.values(AssignVideoFileRequestExtraKind).some((kind) => kind === value)
}

interface PaginationProps {
  page: number
  hasNext: boolean
  busy: boolean
  noun: string
  onPage: (page: number) => void
}

function Pagination({ page, hasNext, busy, noun, onPage }: PaginationProps) {
  if (page === 1 && !hasNext) return null
  return (
    <Row>
      <Button
        variant="outline"
        size="sm"
        disabled={page === 1 || busy}
        onClick={() => onPage(page - 1)}
        aria-label={`Previous ${noun} page`}
      >
        Previous
      </Button>
      <Message>Page {page}</Message>
      <Button
        variant="outline"
        size="sm"
        disabled={!hasNext || busy}
        onClick={() => onPage(page + 1)}
        aria-label={`Next ${noun} page`}
      >
        Next
      </Button>
    </Row>
  )
}
