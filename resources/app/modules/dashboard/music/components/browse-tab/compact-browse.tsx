import { useState, useEffect, useMemo } from 'react';
import {
  Box,
  Flex,
  Text,
  TextField,
  Button,
  Select,
  Spinner,
  ScrollArea,
  Card
} from '@radix-ui/themes';
import { MagnifyingGlassIcon, Cross2Icon } from '@radix-ui/react-icons';
import { Virtuoso } from 'react-virtuoso';
import {
  useMetadataBrowseAlbumsInfinite,
  useMetadataBrowseArtistsInfinite,
  useMetadataBrowseSongsInfinite
} from '@/app/libs/api-client/gen/endpoints/metadata-browse/metadata-browse';
import { MetadataCard, MetadataItem } from './metadata-card';
import { QualityBadge } from './quality-badge';
import { Release } from '@/app/libs/api-client/gen/models/release';
import { AppHttpIntegrationsMusicBrainzModelsRelease } from '@/app/libs/api-client/gen/models/appHttpIntegrationsMusicBrainzModelsRelease';
import { InfiniteData } from '@tanstack/react-query';

export type SearchProvider = 'all' | 'musicbrainz' | 'discogs';

export interface MetadataBrowseItem {
  source: 'musicbrainz' | 'discogs';
  quality_score: number;
  item: Release | AppHttpIntegrationsMusicBrainzModelsRelease;
}

export interface CompactBrowseProps {
  entityType: 'album' | 'artist' | 'song';
  entityName: string;
  onSelectResult: (result: MetadataBrowseItem, source: 'musicbrainz' | 'discogs', externalId: string) => void;
  onClose: () => void;
}

interface ResultItem {
  id: string;
  data: MetadataItem;
  source: 'musicbrainz' | 'discogs';
  qualityScore: number;
  externalId: string;
}

export function CompactBrowse({
  entityType,
  entityName,
  onSelectResult,
  onClose
}: CompactBrowseProps) {
  const [query, setQuery] = useState(entityName);
  const [provider, setProvider] = useState<SearchProvider>('all');
  const [debouncedQuery, setDebouncedQuery] = useState(entityName);
  const [hasSearched, setHasSearched] = useState(false);

  // Debounce search input (300ms)
  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedQuery(query);
      if (query.trim().length >= 2) {
        setHasSearched(true);
      }
    }, 300);
    return () => clearTimeout(handler);
  }, [query]);

  // Build query parameters
  const searchParams = {
    query: debouncedQuery,
    source: provider === 'all' ? undefined : provider,
    page: 1,
    per_page: 20
  };

  // Execute search hook based on entity type
  const albumsQuery = useMetadataBrowseAlbumsInfinite(searchParams, {
    query: {
      enabled: hasSearched && debouncedQuery.length >= 2 && entityType === 'album'
    }
  });

  const artistsQuery = useMetadataBrowseArtistsInfinite(searchParams, {
    query: {
      enabled: hasSearched && debouncedQuery.length >= 2 && entityType === 'artist'
    }
  });

  const songsQuery = useMetadataBrowseSongsInfinite(searchParams, {
    query: {
      enabled: hasSearched && debouncedQuery.length >= 2 && entityType === 'song'
    }
  });

  // Select the active query based on entity type
  const activeQuery = entityType === 'album' ? albumsQuery :
                      entityType === 'artist' ? artistsQuery : songsQuery;

  const { data, isLoading, fetchNextPage, hasNextPage, error, isError } = activeQuery;

  // Flatten results from infinite scroll pages into a single list
  const flattenedResults = useMemo((): ResultItem[] => {
    if (!data?.pages) return [];

    const items: ResultItem[] = [];

    // Flatten all pages
    data.pages.forEach((page, pageIndex) => {
      if (!page.data) return;

      page.data.forEach((resultItem: MetadataBrowseItem, index: number) => {
        const { source, quality_score, item } = resultItem;

        // Generate external ID based on source and item
        let externalId = '';
        if (source === 'musicbrainz') {
          const mbItem = item as AppHttpIntegrationsMusicBrainzModelsRelease;
          externalId = mbItem.id || '';
        } else {
          const discogsItem = item as Release;
          externalId = discogsItem.id?.toString() || '';
        }

        items.push({
          id: `${source}-${externalId}-${index}`,
          data: item,
          source,
          qualityScore: quality_score,
          externalId
        });
      });
    });

    return items;
  }, [data]);

  const handleSelect = (item: ResultItem) => {
    const browseItem: MetadataBrowseItem = {
      source: item.source,
      quality_score: item.qualityScore,
      item: item.data
    };
    onSelectResult(browseItem, item.source, item.externalId);
  };

  const handleLoadMore = () => {
    if (hasNextPage && !isLoading) {
      fetchNextPage();
    }
  };

  const handleClear = () => {
    setQuery('');
    setDebouncedQuery('');
    setHasSearched(false);
  };

  return (
    <Flex direction="column" style={{ height: '600px', maxHeight: '600px' }}>
      {/* Header with search and source selector */}
      <Flex direction="column" gap="3" p="4" style={{ borderBottom: '1px solid var(--gray-4)' }}>
        <Text size="4" weight="bold">
          Browse {entityType === 'album' ? 'Albums' : entityType === 'artist' ? 'Artists' : 'Songs'}
        </Text>

        <Flex gap="3" align="center">
          {/* Source selector */}
          <Select.Root
            value={provider}
            onValueChange={(value) => setProvider(value as SearchProvider)}
          >
            <Select.Trigger placeholder="Source" />
            <Select.Content position="popper" sideOffset={5}>
              <Select.Item value="all">All Sources</Select.Item>
              <Select.Item value="musicbrainz">MusicBrainz</Select.Item>
              <Select.Item value="discogs">Discogs</Select.Item>
            </Select.Content>
          </Select.Root>

          {/* Search input */}
          <TextField.Root
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search..."
            size="2"
            style={{ flexGrow: 1 }}
            disabled={isLoading}
          >
            <TextField.Slot side="right">
              {query && (
                <Button
                  variant="ghost"
                  size="1"
                  onClick={handleClear}
                  disabled={isLoading}
                >
                  <Cross2Icon />
                </Button>
              )}
              <MagnifyingGlassIcon />
            </TextField.Slot>
          </TextField.Root>
        </Flex>
      </Flex>

      {/* Content area */}
      <Flex direction="column" style={{ flexGrow: 1, overflow: 'hidden' }}>
        {/* Loading state (initial search) */}
        {isLoading && !flattenedResults.length && (
          <Flex direction="column" align="center" justify="center" py="6" gap="3">
            <Spinner size="3" />
            <Text color="gray">Searching...</Text>
          </Flex>
        )}

        {/* Error state */}
        {isError && (
          <Box p="4">
            <Text color="red">
              {(error as any)?.message || 'Failed to search metadata'}
            </Text>
          </Box>
        )}

        {/* Empty state (after search) */}
        {!isLoading && hasSearched && !flattenedResults.length && !isError && (
          <Flex direction="column" align="center" justify="center" py="6" gap="3">
            <Text color="gray" size="4">
              No results found
            </Text>
            <Text color="gray" size="2">
              Try adjusting your search query or source filter
            </Text>
          </Flex>
        )}

        {/* Results list */}
        {(flattenedResults.length > 0 || isLoading) && (
          <>
            {flattenedResults.length > 0 && (
              <Box px="4" py="2" style={{ borderBottom: '1px solid var(--gray-4)' }}>
                <Text size="1" color="gray">
                  {flattenedResults.length} result{flattenedResults.length !== 1 ? 's' : ''} found
                </Text>
              </Box>
            )}

            <Box style={{ flex: 1, overflow: 'hidden' }}>
              <Virtuoso
                style={{ height: '100%' }}
                data={flattenedResults}
                endReached={() => {
                  hasNextPage && !isLoading && handleLoadMore();
                }}
                itemContent={(_index, item) => (
                  <Box
                    key={item.id}
                    p="2"
                    style={{ borderBottom: '1px solid var(--gray-4)', cursor: 'pointer' }}
                    onClick={() => handleSelect(item)}
                  >
                    <CompactResultCard
                      data={item.data}
                      source={item.source}
                      qualityScore={item.qualityScore}
                    />
                  </Box>
                )}
              />
            </Box>

            {/* Loading more indicator */}
            {isLoading && flattenedResults.length > 0 && (
              <Flex justify="center" py="2">
                <Spinner size="2" />
                <Text size="2" color="gray" ml="2">Loading more...</Text>
              </Flex>
            )}
          </>
        )}
      </Flex>

      {/* Footer with Cancel button */}
      <Flex justify="end" p="4" style={{ borderTop: '1px solid var(--gray-4)' }}>
        <Button variant="soft" onClick={onClose}>
          Cancel
        </Button>
      </Flex>
    </Flex>
  );
}

// Simplified card component for compact display
interface CompactResultCardProps {
  data: MetadataItem;
  source: 'musicbrainz' | 'discogs';
  qualityScore: number;
}

function isDiscogsRelease(item: MetadataItem): item is Release {
  return 'title' in item && 'uri' in item;
}

function isMusicBrainzRelease(item: MetadataItem): item is AppHttpIntegrationsMusicBrainzModelsRelease {
  return 'id' in item && 'title' in item && !('uri' in item);
}

function formatArtists(item: MetadataItem): string {
  if (isDiscogsRelease(item)) {
    const artists = item.artists as Array<{ name: string }>;
    return artists?.map(a => a.name).join(', ') ?? 'Unknown Artist';
  }

  if (isMusicBrainzRelease(item)) {
    const artistCredits = item.artist_credit as Array<{ name: string }>;
    return artistCredits?.map(a => a.name).join(', ') ?? 'Unknown Artist';
  }

  return 'Unknown Artist';
}

function getTitle(item: MetadataItem): string {
  return item.title ?? 'Unknown Title';
}

function getYear(item: MetadataItem): string {
  if (isDiscogsRelease(item)) {
    return item.year?.toString() ?? item.released?.substring(0, 4) ?? '—';
  }

  if (isMusicBrainzRelease(item)) {
    return item.date?.substring(0, 4) ?? '—';
  }

  return '—';
}

function getSourceColor(source: 'musicbrainz' | 'discogs'): 'blue' | 'orange' {
  return source === 'musicbrainz' ? 'blue' : 'orange';
}

function CompactResultCard({ data, source, qualityScore }: CompactResultCardProps) {
  const title = getTitle(data);
  const artists = formatArtists(data);
  const year = getYear(data);

  return (
    <Card size="1" variant="ghost">
      <Flex gap="2" align="center">
        {/* Metadata info */}
        <Flex direction="column" gap="0" style={{ flexGrow: 1 }}>
          <Flex gap="2" align="center">
            <Text weight="bold" size="2">{title}</Text>
            <Text size="1" color={source === 'musicbrainz' ? 'blue' : 'orange'}>
              {source === 'musicbrainz' ? 'MB' : 'DG'}
            </Text>
            <QualityBadge score={qualityScore} />
          </Flex>

          <Flex gap="3" align="center">
            <Text size="1" color="gray">
              {artists}
            </Text>
            <Text size="1" color="gray">
              {year}
            </Text>
          </Flex>
        </Flex>

        <Text size="1" color="gray">
          Click to select
        </Text>
      </Flex>
    </Card>
  );
}
