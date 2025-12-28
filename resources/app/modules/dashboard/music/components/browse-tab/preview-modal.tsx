import React from 'react';
import {
  Dialog,
  Flex,
  Text,
  Box,
  Grid,
  Badge,
  Button,
  Separator,
  ScrollArea,
  Callout
} from '@radix-ui/themes';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { Release } from '@/app/libs/api-client/gen/models/release';
import { AppHttpIntegrationsMusicBrainzModelsRelease } from '@/app/libs/api-client/gen/models/appHttpIntegrationsMusicBrainzModelsRelease';
import { MetadataItem } from './metadata-card';

export interface PreviewModalProps {
  open: boolean;
  onClose: () => void;
  onApply: () => void;
  isApplying?: boolean;
  newMetadata: {
    data: MetadataItem;
    source: 'musicbrainz' | 'discogs';
    qualityScore: number;
  };
  currentMetadata?: Record<string, any>;
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

export function PreviewModal({
  open,
  onClose,
  onApply,
  isApplying = false,
  newMetadata,
  currentMetadata
}: PreviewModalProps) {
  const { data, source, qualityScore } = newMetadata;

  // Build comparison fields
  const getComparisonFields = () => {
    const fields: Array<{
      key: string;
      label: string;
      current: string;
      new: string;
      changed: boolean;
    }> = [];

    // Title
    fields.push({
      key: 'title',
      label: 'Title',
      current: currentMetadata?.title ?? '—',
      new: data.title ?? '—',
      changed: (data.title ?? '') !== (currentMetadata?.title ?? '')
    });

    // Artists
    const artists = formatArtists(data);
    fields.push({
      key: 'artists',
      label: 'Artists',
      current: currentMetadata?.artist ?? currentMetadata?.artists ?? '—',
      new: artists,
      changed: artists !== (currentMetadata?.artist ?? currentMetadata?.artists ?? '')
    });

    // Year
    const year = isDiscogsRelease(data)
      ? data.year?.toString() ?? data.released?.substring(0, 4) ?? '—'
      : data.date?.substring(0, 4) ?? '—';
    fields.push({
      key: 'year',
      label: 'Year',
      current: currentMetadata?.year?.toString() ?? '—',
      new: year,
      changed: year !== (currentMetadata?.year?.toString() ?? '')
    });

    // Country
    if (data.country) {
      fields.push({
        key: 'country',
        label: 'Country',
        current: currentMetadata?.country ?? '—',
        new: data.country ?? '—',
        changed: (data.country ?? '') !== (currentMetadata?.country ?? '')
      });
    }

    // Barcode (MusicBrainz only)
    if (isMusicBrainzRelease(data) && data.barcode) {
      fields.push({
        key: 'barcode',
        label: 'Barcode',
        current: currentMetadata?.barcode ?? '—',
        new: data.barcode ?? '—',
        changed: (data.barcode ?? '') !== (currentMetadata?.barcode ?? '')
      });
    }

    // Catalog Number
    const catalogNumber = isDiscogsRelease(data)
      ? data.catno
      : isMusicBrainzRelease(data)
        ? (data.catalog_number ?? '—')
        : '—';

    if (catalogNumber && catalogNumber !== '—') {
      fields.push({
        key: 'catalog_number',
        label: 'Catalog Number',
        current: currentMetadata?.catalog_number ?? '—',
        new: catalogNumber,
        changed: catalogNumber !== (currentMetadata?.catalog_number ?? '')
      });
    }

    return fields;
  };

  const comparisonFields = getComparisonFields();
  const changedFields = comparisonFields.filter(f => f.changed);

  return (
    <Dialog.Root open={open} onOpenChange={(open) => !open && onClose()}>
      <Dialog.Content style={{ maxWidth: 800, maxHeight: '80vh' }}>
        <Dialog.Title>
          Preview Metadata Changes
        </Dialog.Title>
        <Dialog.Description>
          Review the metadata changes before applying. Fields in yellow will be updated.
        </Dialog.Description>

        <Box mt="4">
          {/* Quality Score and Source Info */}
          <Flex gap="3" mb="4">
            <Badge color={source === 'musicbrainz' ? 'blue' : 'orange'}>
              Source: {source}
            </Badge>
            <Badge color={qualityScore >= 0.7 ? 'green' : qualityScore >= 0.5 ? 'yellow' : 'red'}>
              Quality: {Math.round(qualityScore * 100)}%
            </Badge>
          </Flex>

          {/* Warning if many changes */}
          {changedFields.length > 5 && (
            <Callout.Root mb="4" color="yellow">
              <Callout.Icon>
                <InfoCircledIcon />
              </Callout.Icon>
              <Callout.Text>
                This will update {changedFields.length} fields. Please review carefully before applying.
              </Callout.Text>
            </Callout.Root>
          )}

          {/* Comparison Table */}
          <ScrollArea style={{ maxHeight: 400 }}>
            <Box my="2">
              <Grid columns="150px 1fr 1fr" gap="2" align="center">
                {/* Header */}
                <Text weight="bold" size="2">Field</Text>
                <Text weight="bold" size="2">Current</Text>
                <Text weight="bold" size="2">New</Text>

                <Box gridColumn="1 / -1" py="2">
                  <Separator size="4" />
                </Box>

                {/* Fields */}
                {comparisonFields.map((field) => (
                  <React.Fragment key={field.key}>
                    <Text size="2" color="gray">
                      {field.label}
                    </Text>
                    <Box
                      p="2"
                      style={{
                        backgroundColor: field.changed ? 'var(--yellow-3)' : 'transparent',
                        borderRadius: '4px'
                      }}
                    >
                      <Text size="2">
                        {field.current}
                      </Text>
                    </Box>
                    <Box
                      p="2"
                      style={{
                        backgroundColor: field.changed ? 'var(--green-3)' : 'transparent',
                        borderRadius: '4px'
                      }}
                    >
                      <Text size="2" weight={field.changed ? 'bold' : undefined}>
                        {field.new}
                      </Text>
                    </Box>
                  </React.Fragment>
                ))}
              </Grid>
            </Box>
          </ScrollArea>
        </Box>

        <Flex gap="3" mt="4" justify="end">
          <Dialog.Close>
            <Button variant="soft" onClick={onClose} disabled={isApplying}>
              Cancel
            </Button>
          </Dialog.Close>
          <Button onClick={onApply} disabled={isApplying}>
            {isApplying ? 'Applying...' : `Apply Changes (${changedFields.length})`}
          </Button>
        </Flex>
      </Dialog.Content>
    </Dialog.Root>
  );
}
