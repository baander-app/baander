import { useState, useCallback } from 'react';
import { Container, Heading, Flex, Callout, Text } from '@radix-ui/themes';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { SearchForm, SearchType, SearchProvider } from './search-form';
import { SearchResults } from './search-results';
import { MetadataItem } from './metadata-card';
import {
  useMetadataBrowseAlbumsInfinite,
  useMetadataBrowseArtistsInfinite,
  useMetadataBrowseSongsInfinite,
  useMetadataBrowseApply
} from '@/app/libs/api-client/gen/endpoints/metadata-browse/metadata-browse';
import { ApplyMetadataRequest } from '@/app/libs/api-client/gen/models';

export interface BrowseTabProps {
  currentMetadata?: Record<string, any>;
  onMetadataApplied?: (newMetadata: any) => void;
  entityType?: 'album' | 'artist' | 'song';
  entityId?: string | number;
  entityName?: string;
}

export function BrowseTab({
  currentMetadata,
  onMetadataApplied,
  entityType = 'album',
  entityId,
  entityName
}: BrowseTabProps) {
  const [searchQuery, setSearchQuery] = useState(entityName || '');
  const [searchType, setSearchType] = useState<SearchType>(entityType);
  const [searchProvider, setSearchProvider] = useState<SearchProvider>('all');
  const [hasSearched, setHasSearched] = useState(!!entityName);

  // Build query parameters
  const searchParams = {
    query: searchQuery,
    source: searchProvider === 'all' ? undefined : searchProvider,
    page: 1,
    per_page: 20
  };

  // Reusable getNextPageParam for infinite queries
  const getNextPageParam = useCallback((lastPage: any) => {
    if (!lastPage) return undefined;
    // The response structure: { data, pagination: { page, per_page, total }, sources }
    if (lastPage?.pagination) {
      const { page, per_page, total } = lastPage.pagination;
      const loadedItems = page * per_page;
      return loadedItems < total ? page + 1 : undefined;
    }
    return undefined;
  }, []);

  // Execute search hook based on type
  const albumsQuery = useMetadataBrowseAlbumsInfinite(searchParams, {
    query: {
      enabled: hasSearched && searchQuery.length >= 2 && searchType === 'album',
      initialPageParam: 1,
      getNextPageParam
    }
  });

  const artistsQuery = useMetadataBrowseArtistsInfinite(searchParams, {
    query: {
      enabled: hasSearched && searchQuery.length >= 2 && searchType === 'artist',
      initialPageParam: 1,
      getNextPageParam
    }
  });

  const songsQuery = useMetadataBrowseSongsInfinite(searchParams, {
    query: {
      enabled: hasSearched && searchQuery.length >= 2 && searchType === 'song',
      initialPageParam: 1,
      getNextPageParam
    }
  });

  // Select the active query based on search type
  const activeQuery = searchType === 'album' ? albumsQuery :
                      searchType === 'artist' ? artistsQuery : songsQuery;

  const { data, isLoading, fetchNextPage, hasNextPage, error, isError } = activeQuery;

  // Apply metadata mutation using generated hook
  const applyMutation = useMetadataBrowseApply({
    mutation: {
      onSuccess: (_data) => {
        onMetadataApplied?.(_data);
      },
      onError: (err: any) => {
        console.error('Failed to apply metadata:', err);
      }
    }
  });

  const handleSearch = useCallback((query: string, type: SearchType, provider: SearchProvider) => {
    setSearchQuery(query);
    setSearchType(type);
    setSearchProvider(provider);
    setHasSearched(query.trim().length >= 2);
  }, []);

  const handleLoadMore = useCallback(() => {
    if (hasNextPage && !isLoading) {
      fetchNextPage();
    }
  }, [hasNextPage, isLoading, fetchNextPage]);

  const handleApplyMetadata = useCallback((_item: MetadataItem, source: 'musicbrainz' | 'discogs', externalId: string) => {
    if (!entityId) {
      console.error('Cannot apply metadata: no entity ID provided');
      return;
    }

    applyMutation.mutate({
      data: {
        entity_type: entityType as ApplyMetadataRequest['entity_type'],
        // TODO: Backend needs to support public_id or we need to expose database id in resources
        entity_id: entityId as unknown as number,
        source: source as ApplyMetadataRequest['source'],
        external_id: externalId
      }
    });
  }, [applyMutation, entityType, entityId]);

  return (
    <Container>
      <Flex direction="column" gap="4">
        <Heading size="4">Browse Metadata</Heading>

        {/* Info callout if no current metadata */}
        {!currentMetadata && (
          <Callout.Root color="blue">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              Search and browse metadata from MusicBrainz and Discogs to find and apply to your local library.
            </Callout.Text>
          </Callout.Root>
        )}

        {/* Search form */}
        <SearchForm
          onSearch={handleSearch}
          isLoading={isLoading}
          defaultType={entityType}
          defaultQuery={entityName}
        />

        {/* Search results */}
        {hasSearched && (
          <SearchResults
            data={data}
            isLoading={isLoading}
            error={isError ? (error as any)?.message || 'Failed to search metadata' : null}
            onApplyMetadata={handleApplyMetadata}
            isApplying={applyMutation.isPending}
            currentMetadata={currentMetadata}
            onLoadMore={handleLoadMore}
            hasMore={hasNextPage || false}
            searchType={searchType}
          />
        )}

        {/* Initial state */}
        {!hasSearched && (
          <Flex direction="column" align="center" justify="center" py="9" gap="3">
            <Text color="gray" size="5">
              Start Browsing
            </Text>
            <Text color="gray" size="2">
              Use the search form above to find metadata from MusicBrainz and Discogs
            </Text>
          </Flex>
        )}
      </Flex>
    </Container>
  );
}
