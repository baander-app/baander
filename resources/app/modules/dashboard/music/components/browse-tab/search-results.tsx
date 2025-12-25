import { useState, useMemo } from 'react';
import { Flex, Text, Box, Button, Callout, Spinner } from '@radix-ui/themes';
import { Virtuoso } from 'react-virtuoso';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { MetadataCard, MetadataItem } from './metadata-card';
import { PreviewModal } from './preview-modal';
import { Release } from '@/app/libs/api-client/gen/models/release';
import { AppHttpIntegrationsMusicBrainzModelsRelease } from '@/app/libs/api-client/gen/models/appHttpIntegrationsMusicBrainzModelsRelease';
import { SearchType } from './search-form';
import styles from './search-results.module.scss';
import { InfiniteData } from '@tanstack/react-query';

// API response types from generated hooks
export interface MetadataBrowseItem {
  source: 'musicbrainz' | 'discogs';
  quality_score: number;
  item: Release | AppHttpIntegrationsMusicBrainzModelsRelease;
}

export interface SearchResultsProps {
  data: InfiniteData<any, any> | undefined;
  isLoading: boolean;
  error: string | null;
  onApplyMetadata: (item: MetadataItem, source: 'musicbrainz' | 'discogs', externalId: string) => void;
  isApplying?: boolean;
  currentMetadata?: Record<string, any>;
  onLoadMore?: () => void;
  hasMore?: boolean;
  searchType: SearchType;
}

interface ResultItem {
  id: string;
  data: MetadataItem;
  source: 'musicbrainz' | 'discogs';
  qualityScore: number;
  externalId: string;
}

export function SearchResults({
  data,
  isLoading,
  error,
  onApplyMetadata,
  isApplying = false,
  currentMetadata,
  onLoadMore,
  hasMore = false,
  searchType: _searchType
}: SearchResultsProps) {
  const [previewItem, setPreviewItem] = useState<ResultItem | null>(null);

  // Flatten results from infinite scroll pages into a single list
  const flattenedResults = useMemo((): ResultItem[] => {
    console.log('🔍 SearchResults - data:', data);
    console.log('🔍 SearchResults - pages:', data?.pages);

    if (!data?.pages) return [];

    const items: ResultItem[] = [];

    // Flatten all pages
    data.pages.forEach((page, pageIndex) => {
      console.log(`🔍 Page ${pageIndex}:`, page);
      if (!page.data) {
        console.log(`⚠️ Page ${pageIndex} has no data property`);
        return;
      }

      console.log(`✅ Page ${pageIndex} has ${page.data.length} items`);

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

    console.log(`✅ Total flattened items: ${items.length}`);
    return items;
  }, [data]);

  const handlePreview = (item: ResultItem) => {
    setPreviewItem(item);
  };

  const handleApply = (item: ResultItem) => {
    onApplyMetadata(item.data, item.source, item.externalId);
    setPreviewItem(null);
  };

  const handleApplyFromPreview = () => {
    if (previewItem) {
      handleApply(previewItem);
    }
  };

  // Loading state
  if (isLoading && !flattenedResults.length) {
    return (
      <Flex direction="column" align="center" justify="center" py="9" gap="3">
        <Spinner size="3" />
        <Text color="gray">Searching metadata providers...</Text>
      </Flex>
    );
  }

  // Error state
  if (error) {
    return (
      <Box p="4">
        <Callout.Root color="red">
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <Callout.Text>
            {error}
          </Callout.Text>
        </Callout.Root>
      </Box>
    );
  }

  // Empty state
  if (!flattenedResults.length) {
    return (
      <Flex direction="column" align="center" justify="center" py="9" gap="3">
        <Text color="gray" size="5">
          No results found
        </Text>
        <Text color="gray" size="2">
          Try adjusting your search query or filters
        </Text>
      </Flex>
    );
  }

  const totalResults = flattenedResults.length;

  return (
    <div>
      {/* Results header */}
      <Box p="3" style={{ borderBottom: '1px solid var(--gray-4)' }}>
        <Flex justify="between" align="center">
          <Text size="2" color="gray">
            Found {totalResults} results
          </Text>
          {hasMore && (
            <Button size="1" variant="outline" onClick={onLoadMore} disabled={isLoading}>
              Load More
            </Button>
          )}
        </Flex>
      </Box>

      {/* Virtualized list */}
      <Virtuoso
        style={{ height: '100vh' }}
        data={flattenedResults}
        useWindowScroll={true}
        endReached={() => {
          hasMore && !isLoading && onLoadMore?.();
        }}
        itemContent={(_index, item) => (
          <Box key={item.id} p="2" style={{ borderBottom: '1px solid var(--gray-4)' }}>
            <MetadataCard
              data={item.data}
              source={item.source}
              qualityScore={item.qualityScore}
              onPreview={() => handlePreview(item)}
              onApply={() => handleApply(item)}
            />
          </Box>
        )}
      />

      {/* Preview Modal */}
      {previewItem && (
        <PreviewModal
          open={!!previewItem}
          onClose={() => setPreviewItem(null)}
          onApply={handleApplyFromPreview}
          isApplying={isApplying}
          newMetadata={{
            data: previewItem.data,
            source: previewItem.source,
            qualityScore: previewItem.qualityScore
          }}
          currentMetadata={currentMetadata}
        />
      )}
    </div>
  );
}
