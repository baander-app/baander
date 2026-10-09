import styled from 'styled-components'
import { useState, useCallback } from 'react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/shared/components/ui/dialog'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { ScrollArea } from '@/shared/components/ui/scroll-area'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/shared/components/ui/tabs'
import {
  useGetLyricsSongLyrics,
  usePostLyricsSongLyricsFetch,
  useGetLyricsSearch,
  usePostLyricsApply,
  getGetLyricsSongLyricsQueryKey,
} from '@/shared/api-client/gen/endpoints'
import type {
  GetLyricsSongLyrics200,
  GetLyricsSearch200,
  LyricsResource,
  LrclibSearchResource,
} from '@/shared/api-client/gen/endpoints'
import { useIsAdmin } from '@/features/auth/hooks/use-admin-check'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Search, Download, Check, Music } from 'lucide-react'
import { formatDuration } from '@/shared/utils/format-duration'
import { interactiveTransition } from '@/shared/theme'
import { lyricsErrorMessage } from './lyrics-error-message'

const LyricsPre = styled.pre`
  white-space: pre-wrap;
  font-family: var(--font-sans);
  font-size: 0.875rem;
  line-height: 1.625;
  color: var(--color-foreground);
`

const InstrumentalRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SourceText = styled.p`
  margin-top: 0.5rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const NoLyricsContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 1.5rem 0;
`

const NoLyricsText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SearchRow = styled.div`
  display: flex;
  gap: 0.5rem;
`

const SearchResultsList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const SearchResultButton = styled.button`
  display: flex;
  width: 100%;
  align-items: flex-start;
  gap: 0.75rem;
  border-radius: var(--radius-md);
  padding: 0.5rem 0.75rem;
  text-align: left;
  ${interactiveTransition(['color', 'background-color'])}
  background: none;
  border: none;
  cursor: pointer;

  &:hover {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }

  &:disabled {
    opacity: 0.5;
  }
`

const SearchResultContent = styled.div`
  min-width: 0;
  flex: 1;
`

const SearchResultTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
`

const SearchResultMeta = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const SearchResultPreview = styled.p`
  margin-top: 0.25rem;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
`

const SearchHint = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  text-align: center;
`

const NoResultsText = styled.p`
  padding: 1rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SyncedControls = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

interface LyricsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  songPublicId: string
  songTitle: string
  artistName?: string
}

type CachedLyrics = LyricsResource

type SearchResult = LrclibSearchResource

export function LyricsDialog({
  open,
  onOpenChange,
  songPublicId,
  songTitle,
  artistName,
}: LyricsDialogProps) {
  const isAdmin = useIsAdmin()
  const [tab, setTab] = useState<'lyrics' | 'search'>('lyrics')
  const [searchQuery, setSearchQuery] = useState('')
  const activeTab = isAdmin ? tab : 'lyrics'

  const queryClient = useQueryClient()

  // Fetch cached lyrics
  const { data: lyricsData, isLoading: lyricsLoading, isError: lyricsError } = useGetLyricsSongLyrics(songPublicId, {
    query: { enabled: open },
  })

  const lyrics = extractLyrics(lyricsData)
  const hasLyrics = !!(lyrics?.plainLyrics || lyrics?.syncedLyrics)

  // Fetch from LRCLIB
  const fetchMutation = usePostLyricsSongLyricsFetch({
    mutation: {
      onSuccess: (data, variables) => {
        queryClient.invalidateQueries({ queryKey: getGetLyricsSongLyricsQueryKey(variables.publicId) })
        // LRCLIB having no lyrics is a success with an empty array.
        if (!data.data || Array.isArray(data.data)) {
          toast.info('No lyrics found on LRCLIB')
          return
        }
        toast.success('Lyrics fetched')
      },
      onError: (error) => {
        toast.error(lyricsErrorMessage(error, 'Failed to fetch lyrics'))
      },
    },
  })

  // Search LRCLIB
  const { data: searchData, isLoading: searchLoading, error: searchError } = useGetLyricsSearch(
    { q: searchQuery },
    { query: { enabled: open && isAdmin && activeTab === 'search' && searchQuery.length >= 2 } },
  )

  const searchResults = extractSearchResults(searchData)

  // Apply search result
  const applyMutation = usePostLyricsApply({
    mutation: {
      onSuccess: (_data, variables) => {
        queryClient.invalidateQueries({ queryKey: getGetLyricsSongLyricsQueryKey(variables.data.songPublicId) })
        toast.success('Lyrics applied')
        setTab('lyrics')
      },
      onError: (error) => {
        toast.error(lyricsErrorMessage(error, 'Failed to apply lyrics'))
      },
    },
  })

  const handleFetch = useCallback(() => {
    if (!isAdmin) return

    fetchMutation.mutate({ publicId: songPublicId })
  }, [fetchMutation, isAdmin, songPublicId])

  const handleSearch = useCallback(() => {
    if (!isAdmin) return

    const q = [songTitle, artistName].filter(Boolean).join(' ')
    setSearchQuery(q)
    setTab('search')
  }, [isAdmin, songTitle, artistName])

  const handleApply = useCallback(
    (resultId: number) => {
      if (!isAdmin) return

      applyMutation.mutate({ resultId, data: { songPublicId } })
    },
    [applyMutation, isAdmin, songPublicId],
  )

  const defaultQuery = [songTitle, artistName].filter(Boolean).join(' ')

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Lyrics \u2014 {songTitle}</DialogTitle>
          <DialogDescription>
            {artistName ?? 'View lyrics for this track'}
          </DialogDescription>
        </DialogHeader>

        <Tabs
          value={activeTab}
          onValueChange={(value) => {
            if (value === 'lyrics' || (isAdmin && value === 'search')) {
              setTab(value)
            }
          }}
        >
          <TabsList>
            <TabsTrigger value="lyrics">Lyrics</TabsTrigger>
            {isAdmin && <TabsTrigger value="search">Search</TabsTrigger>}
          </TabsList>

          <TabsContent value="lyrics">
            {lyricsLoading ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                <Skeleton style={{ height: '1rem', width: '100%' }} />
                <Skeleton style={{ height: '1rem', width: '80%' }} />
                <Skeleton style={{ height: '1rem', width: '100%' }} />
                <Skeleton style={{ height: '1rem', width: '60%' }} />
              </div>
            ) : lyricsError ? (
              <NoLyricsContainer>
                <NoLyricsText>Unable to load lyrics</NoLyricsText>
              </NoLyricsContainer>
            ) : hasLyrics ? (
              <ScrollArea style={{ height: '18rem' }}>
                {lyrics.syncedLyrics ? (
                  <SyncedLyricsView synced={lyrics.syncedLyrics} plain={lyrics.plainLyrics ?? undefined} />
                ) : (
                  <LyricsPre>{lyrics.plainLyrics}</LyricsPre>
                )}
                {lyrics.isInstrumental && (
                  <InstrumentalRow>
                    <Music size={14} />
                    Instrumental
                  </InstrumentalRow>
                )}
                {lyrics.source && (
                  <SourceText>Source: {lyrics.source}</SourceText>
                )}
              </ScrollArea>
            ) : (
              <NoLyricsContainer>
                <NoLyricsText>No lyrics cached for this track.</NoLyricsText>
                {isAdmin && (
                  <Button
                    size="sm"
                    onClick={handleFetch}
                    disabled={fetchMutation.isPending}
                  >
                    <Download size={14} />
                    {fetchMutation.isPending ? 'Fetching\u2026' : 'Fetch from LRCLIB'}
                  </Button>
                )}
              </NoLyricsContainer>
            )}
          </TabsContent>

          {isAdmin && (
            <TabsContent value="search">
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                <SearchRow>
                  <Input
                    placeholder="Search lyrics\u2026"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && searchQuery.length >= 2) {
                        // refetch happens via react-query enabled
                      }
                    }}
                  />
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={handleSearch}
                    style={{ flexShrink: 0 }}
                  >
                    <Search size={14} />
                    Auto
                  </Button>
                </SearchRow>

                {searchLoading ? (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                    {Array.from({ length: 3 }).map((_, i) => (
                      <Skeleton key={i} style={{ height: '4rem', width: '100%', borderRadius: 'var(--radius-md)' }} />
                    ))}
                  </div>
                ) : searchError && searchQuery.length >= 2 ? (
                  <NoResultsText role="alert">{lyricsErrorMessage(searchError, 'Search failed. Try again.')}</NoResultsText>
                ) : searchResults.length === 0 && searchQuery.length >= 2 ? (
                  <NoResultsText>No results found for &ldquo;{searchQuery}&rdquo;</NoResultsText>
                ) : (
                  <ScrollArea style={{ height: '16rem' }}>
                    <SearchResultsList>
                      {searchResults.map((result) => (
                        <SearchResultItem
                          key={result.id}
                          result={result}
                          onApply={handleApply}
                          isApplying={applyMutation.isPending}
                        />
                      ))}
                    </SearchResultsList>
                  </ScrollArea>
                )}

                {!searchQuery && defaultQuery && (
                  <SearchHint>Press &ldquo;Auto&rdquo; to search for &ldquo;{defaultQuery}&rdquo;</SearchHint>
                )}
              </div>
            </TabsContent>
          )}
        </Tabs>

        <DialogFooter showCloseButton />
      </DialogContent>
    </Dialog>
  )
}

function SyncedLyricsView({ synced, plain }: { synced: string; plain?: string }) {
  const [showSynced, setShowSynced] = useState(true)

  const lines = synced.split('\n').filter((l) => l.trim())
  const parsed = lines.map((line) => {
    const match = line.match(/^\[(\d{2}):(\d{2})\.(\d{2,3})\](.*)$/)
    if (!match) return { time: '', text: line }
    const [, min, sec, , text] = match
    return { time: `${min}:${sec}`, text: text.trim() }
  })

  return (
    <SyncedControls>
      <Button
        variant="ghost"
        size="sm"
        style={{ alignSelf: 'flex-start', fontSize: '0.75rem' }}
        onClick={() => setShowSynced(!showSynced)}
      >
        {showSynced ? 'Hide timestamps' : 'Show timestamps'}
      </Button>
      <LyricsPre>
        {showSynced
          ? parsed.map((l) => (l.time ? `${l.time} ${l.text}` : l.text)).join('\n')
          : (plain ?? parsed.map((l) => l.text).join('\n'))
        }
      </LyricsPre>
    </SyncedControls>
  )
}

function SearchResultItem({
  result,
  onApply,
  isApplying,
}: {
  result: SearchResult
  onApply: (id: number) => void
  isApplying: boolean
}) {
  return (
    <SearchResultButton
      type="button"
      onClick={() => onApply(result.id)}
      disabled={isApplying}
    >
      <SearchResultContent>
        <SearchResultTitle>{result.trackName ?? 'Unknown'}</SearchResultTitle>
        <SearchResultMeta>
          {[result.artistName, result.albumName].filter(Boolean).join(' \u00b7 ')}
          {result.duration ? ` \u00b7 ${formatDuration(result.duration)}` : ''}
          {result.instrumental ? ' \u00b7 Instrumental' : ''}
        </SearchResultMeta>
        {result.plainLyrics && (
          <SearchResultPreview>
            {result.plainLyrics.slice(0, 120)}\u2026
          </SearchResultPreview>
        )}
      </SearchResultContent>
      <Check size={14} style={{ marginTop: '0.25rem', flexShrink: 0, color: 'var(--color-muted-foreground)' }} />
    </SearchResultButton>
  )
}

function extractLyrics(data: GetLyricsSongLyrics200 | undefined): CachedLyrics | null {
  const d = data?.data
  if (!d || Array.isArray(d)) return null
  return d
}

function extractSearchResults(data: GetLyricsSearch200 | undefined): SearchResult[] {
  const d = data?.data
  if (!Array.isArray(d)) return []
  return d
}
