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
  Callout,
  IconButton
} from '@radix-ui/themes';
import { InfoCircledIcon, LockClosedIcon } from '@radix-ui/react-icons';
import { Release } from '@/app/libs/api-client/gen/models/release';
import { AppHttpIntegrationsMusicBrainzModelsRelease } from '@/app/libs/api-client/gen/models/appHttpIntegrationsMusicBrainzModelsRelease';
import { MetadataItem } from './metadata-card';

export interface DiffConfirmationProps {
  open: boolean;
  currentMetadata: Record<string, any>;
  newMetadata: {
    data: MetadataItem;
    source: 'musicbrainz' | 'discogs';
  };
  entityType: 'album' | 'artist' | 'song';
  onConfirm: () => void;
  onCancel: () => void;
}

interface DiffField {
  key: string;
  label: string;
  currentValue: string;
  newValue: string;
  changed: boolean;
  isNew: boolean;
  locked?: boolean;
}

function isDiscogsRelease(item: MetadataItem): item is Release {
  return 'title' in item && 'uri' in item;
}

function isMusicBrainzRelease(item: MetadataItem): item is AppHttpIntegrationsMusicBrainzModelsRelease {
  return 'id' in item && 'title' in item && !('uri' in item);
}

// Field mapping definitions for each entity type
const FIELD_MAPPINGS = {
  album: {
    // Direct mappings
    'title': { label: 'Title', source: 'title' },
    'year': { label: 'Year', source: ['year', 'date'] },
    'country': { label: 'Country', source: 'country' },
    'barcode': { label: 'Barcode', source: 'barcode' },
    'catalogNumber': { label: 'Catalog Number', source: ['catalog_number', 'catno'] },
    'label': { label: 'Label', source: 'label' },

    // ID mappings
    'mbid': {
      label: 'MusicBrainz ID',
      source: 'id',
      sourceType: 'musicbrainz',
      targetField: 'mbid'
    },
    'discogsId': {
      label: 'Discogs ID',
      source: 'id',
      sourceType: 'discogs',
      targetField: 'discogsId'
    },
  },
  artist: {
    'name': { label: 'Name', source: 'name' },
    'sortName': { label: 'Sort Name', source: ['sort_name', 'sortName'] },
    'disambiguation': { label: 'Disambiguation', source: 'disambiguation' },
    'type': { label: 'Type', source: 'type' },
    'gender': { label: 'Gender', source: 'gender' },
    'country': { label: 'Country', source: 'country' },
    'mbid': {
      label: 'MusicBrainz ID',
      source: 'id',
      sourceType: 'musicbrainz',
      targetField: 'mbid'
    },
    'discogsId': {
      label: 'Discogs ID',
      source: 'id',
      sourceType: 'discogs',
      targetField: 'discogsId'
    },
  },
  song: {
    'title': { label: 'Title', source: 'title' },
    'year': { label: 'Year', source: ['year', 'date'] },
    'track': { label: 'Track Number', source: ['number', 'track', 'position'] },
    'disc': { label: 'Disc Number', source: 'media' },
    'length': { label: 'Length', source: 'length' },
    'mbid': {
      label: 'MusicBrainz ID',
      source: 'id',
      sourceType: 'musicbrainz',
      targetField: 'mbid'
    },
    'discogsId': {
      label: 'Discogs ID',
      source: 'id',
      sourceType: 'discogs',
      targetField: 'discogsId'
    },
  }
};

// Fields that are considered "locked" and shouldn't be updated
const LOCKED_FIELDS = {
  album: ['publicId', 'cover', 'artists', 'songs', 'createdAt', 'updatedAt'],
  artist: ['publicId', 'portrait', 'createdAt', 'updatedAt'],
  song: ['publicId', 'album', 'artists', 'path', 'hash', 'streamUrl', 'createdAt', 'updatedAt', 'librarySlug']
};

function extractFieldValue(
  item: MetadataItem,
  fieldConfig: any,
  source: 'musicbrainz' | 'discogs'
): string {
  const sourceFields = Array.isArray(fieldConfig.source) ? fieldConfig.source : [fieldConfig.source];

  for (const sourceField of sourceFields) {
    // Special handling for nested objects (e.g., label_info[0].label.name)
    if (sourceField.includes('.')) {
      const parts = sourceField.split('.');
      let value: any = item as any;
      for (const part of parts) {
        if (value && typeof value === 'object') {
          // Handle array access (e.g., label_info[0])
          if (part.includes('[') && part.includes(']')) {
            const arrayField = part.split('[')[0];
            const index = parseInt(part.match(/\[(\d+)\]/)?.[1] || '0');
            value = value[arrayField]?.[index];
          } else {
            value = value[part];
          }
        } else {
          value = undefined;
          break;
        }
      }
      if (value !== undefined && value !== null) {
        return String(value);
      }
    } else {
      const value = (item as any)[sourceField];
      if (value !== undefined && value !== null) {
        return String(value);
      }
    }
  }

  return '—';
}

function getCurrentValue(metadata: Record<string, any>, fieldKey: string): string {
  if (!metadata) return '—';

  const value = metadata[fieldKey];

  if (value === undefined || value === null) {
    return '—';
  }

  // Handle special cases
  if (fieldKey === 'year' && value) {
    // Extract year from date string if needed
    if (typeof value === 'string' && value.length >= 4) {
      return value.substring(0, 4);
    }
    return String(value);
  }

  // Handle arrays (e.g., artists)
  if (Array.isArray(value)) {
    if (fieldKey === 'artists' || fieldKey === 'artist_credit') {
      return value.map((a: any) => a.name || a).join(', ');
    }
    return value.join(', ');
  }

  return String(value);
}

function buildDiffFields(
  currentMetadata: Record<string, any>,
  newMetadata: MetadataItem,
  source: 'musicbrainz' | 'discogs',
  entityType: 'album' | 'artist' | 'song'
): DiffField[] {
  const fields: DiffField[] = [];
  const fieldMappings = FIELD_MAPPINGS[entityType];
  const lockedFields = LOCKED_FIELDS[entityType] || [];

  // Check if source matches for ID fields
  const isMatchingSource = (fieldKey: string): boolean => {
    const config = fieldMappings[fieldKey];
    if (!config || !config.sourceType) return true;
    return config.sourceType === source;
  };

  for (const [fieldKey, fieldConfig] of Object.entries(fieldMappings)) {
    const isLocked = lockedFields.includes(fieldKey);
    const isNew = !currentMetadata || currentMetadata[fieldKey] === undefined || currentMetadata[fieldKey] === null;

    const currentValue = getCurrentValue(currentMetadata, fieldKey);
    const newValue = extractFieldValue(newMetadata, fieldConfig, source);

    // Skip ID fields if source doesn't match
    if (!isMatchingSource(fieldKey)) {
      continue;
    }

    // Format year values consistently
    let formattedCurrentValue = currentValue;
    let formattedNewValue = newValue;

    if (fieldKey === 'year') {
      formattedCurrentValue = currentValue.length === 4 ? currentValue : currentValue.substring(0, 4);
      formattedNewValue = newValue === '—' ? '—' :
        (newValue.length >= 4 ? newValue.substring(0, 4) : newValue);
    }

    // Handle artists field specially for albums
    if (fieldKey === 'artists' && entityType === 'album') {
      const artists = isMusicBrainzRelease(newMetadata)
        ? (newMetadata.artist_credit as Array<{ name: string }>)?.map(a => a.name).join(', ')
        : (newMetadata as Release).artists?.map((a: any) => a.name).join(', ');

      formattedNewValue = artists || '—';
      formattedCurrentValue = currentMetadata?.artists?.map((a: any) => a.name).join(', ') || '—';
    }

    const changed = formattedNewValue !== '—' && formattedNewValue !== formattedCurrentValue;

    fields.push({
      key: fieldKey,
      label: (fieldConfig as any).label,
      currentValue: formattedCurrentValue,
      newValue: formattedNewValue,
      changed,
      isNew,
      locked: isLocked
    });
  }

  return fields;
}

export function DiffConfirmation({
  open,
  currentMetadata,
  newMetadata,
  entityType,
  onConfirm,
  onCancel
}: DiffConfirmationProps) {
  const { data, source } = newMetadata;

  const diffFields = buildDiffFields(currentMetadata, data, source, entityType);
  const changedFields = diffFields.filter(f => f.changed && !f.locked);
  const newFields = diffFields.filter(f => f.isNew && !f.locked);
  const lockedFields = diffFields.filter(f => f.locked);
  const unchangedFields = diffFields.filter(f => !f.changed && !f.isNew && !f.locked);

  const totalChanges = changedFields.length + newFields.length;

  // Group fields by status for better display
  const fieldsToUpdate = [...newFields.filter(f => f.changed || f.isNew), ...changedFields];
  const fieldsToKeep = [...unchangedFields, ...lockedFields.filter(f => !f.changed)];

  return (
    <Dialog.Root open={open} onOpenChange={(isOpen) => !isOpen && onCancel()}>
      <Dialog.Content style={{ maxWidth: 900, maxHeight: '85vh' }}>
        <Flex direction="column" style={{ height: '100%' }}>
          {/* Header */}
          <Dialog.Title>
            Confirm Metadata Updates
          </Dialog.Title>
          <Dialog.Description mb="4">
            Review the metadata changes before applying. Fields in <Text color="green">green</Text> will be updated.
          </Dialog.Description>

          {/* Source Info */}
          <Flex gap="3" mb="4">
            <Badge color={source === 'musicbrainz' ? 'blue' : 'orange'}>
              Source: {source === 'musicbrainz' ? 'MusicBrainz' : 'Discogs'}
            </Badge>
            <Badge color="gray">
              Entity: {entityType}
            </Badge>
            {totalChanges > 0 && (
              <Badge color="green">
                {totalChanges} field{totalChanges !== 1 ? 's' : ''} to update
              </Badge>
            )}
          </Flex>

          {/* Warning for many changes */}
          {totalChanges > 5 && (
            <Callout.Root mb="4" color="yellow">
              <Callout.Icon>
                <InfoCircledIcon />
              </Callout.Icon>
              <Callout.Text>
                This will update {totalChanges} fields. Please review carefully before applying.
              </Callout.Text>
            </Callout.Root>
          )}

          {/* Comparison Table */}
          <Box style={{ flexGrow: 1, overflow: 'hidden' }}>
            <ScrollArea style={{ maxHeight: 'calc(85vh - 250px)', minHeight: 300 }}>
              <Box my="2">
                {/* Fields that will be updated */}
                {fieldsToUpdate.length > 0 && (
                  <>
                    <Flex align="center" gap="2" mb="2" mt="2">
                      <Badge color="green" size="1">
                        Will Update ({fieldsToUpdate.length})
                      </Badge>
                      <Separator orientation="horizontal" size="2" style={{ flexGrow: 1 }} />
                    </Flex>

                    {fieldsToUpdate.map((field) => (
                      <React.Fragment key={field.key}>
                        <Grid
                          columns="140px 1fr 1fr 40px"
                          gap="2"
                          align="center"
                          py="2"
                          px="2"
                          style={{
                            backgroundColor: 'var(--gray-3)',
                            borderRadius: '4px',
                            marginBottom: '4px'
                          }}
                        >
                          {/* Field Label */}
                          <Text size="2" weight="bold">
                            {field.label}
                          </Text>

                          {/* Current Value */}
                          <Box
                            p="2"
                            style={{
                              backgroundColor: field.changed ? 'var(--red-3)' : 'transparent',
                              borderRadius: '4px',
                              minHeight: '32px',
                              display: 'flex',
                              alignItems: 'center'
                            }}
                          >
                            <Text
                              size="2"
                              color={field.isNew ? 'gray' : undefined}
                              style={{
                                textDecoration: field.changed && !field.isNew ? 'line-through' : undefined,
                                opacity: field.isNew ? 0.6 : 1
                              }}
                            >
                              {field.currentValue}
                            </Text>
                          </Box>

                          {/* New Value */}
                          <Box
                            p="2"
                            style={{
                              backgroundColor: 'var(--green-3)',
                              borderRadius: '4px',
                              minHeight: '32px',
                              display: 'flex',
                              alignItems: 'center'
                            }}
                          >
                            <Text size="2" weight="bold">
                              {field.newValue}
                            </Text>
                          </Box>

                          {/* Status Icon */}
                          <Box style={{ display: 'flex', justifyContent: 'center' }}>
                            {field.isNew ? (
                              <Badge color="green" size="1">NEW</Badge>
                            ) : (
                              <Text size="1" color="gray">→</Text>
                            )}
                          </Box>
                        </Grid>
                      </React.Fragment>
                    ))}
                  </>
                )}

                {/* Fields that will remain unchanged */}
                {fieldsToKeep.length > 0 && (
                  <>
                    <Flex align="center" gap="2" mb="2" mt={fieldsToUpdate.length > 0 ? '4' : '2'}>
                      <Badge color="gray" size="1">
                        Unchanged ({fieldsToKeep.length})
                      </Badge>
                      <Separator orientation="horizontal" size="2" style={{ flexGrow: 1 }} />
                    </Flex>

                    {fieldsToKeep.map((field) => (
                      <React.Fragment key={field.key}>
                        <Grid
                          columns="140px 1fr 1fr 40px"
                          gap="2"
                          align="center"
                          py="1"
                          px="2"
                          style={{
                            opacity: 0.6,
                            marginBottom: '2px'
                          }}
                        >
                          <Text size="2" color="gray">
                            {field.label}
                          </Text>

                          <Text size="2" color="gray">
                            {field.currentValue}
                          </Text>

                          <Text size="2" color="gray">
                            {field.newValue}
                          </Text>

                          <Box style={{ display: 'flex', justifyContent: 'center' }}>
                            {field.locked ? (
                              <IconButton
                                size="1"
                                color="gray"
                                variant="ghost"
                                title="Locked field - won't be updated"
                              >
                                <LockClosedIcon />
                              </IconButton>
                            ) : null}
                          </Box>
                        </Grid>
                      </React.Fragment>
                    ))}
                  </>
                )}

                {/* Legend */}
                <Flex gap="4" mt="4" pt="3" style={{ borderTop: '1px solid var(--gray-6)' }}>
                  <Flex align="center" gap="2">
                    <Box
                      width="4"
                      height="4"
                      style={{ backgroundColor: 'var(--green-3)', borderRadius: '2px' }}
                    />
                    <Text size="1" color="gray">New value</Text>
                  </Flex>
                  <Flex align="center" gap="2">
                    <Box
                      width="4"
                      height="4"
                      style={{ backgroundColor: 'var(--red-3)', borderRadius: '2px' }}
                    />
                    <Text size="1" color="gray">Old value (will be replaced)</Text>
                  </Flex>
                  <Flex align="center" gap="2">
                    <Badge color="green" size="1">NEW</Badge>
                    <Text size="1" color="gray">New field</Text>
                  </Flex>
                  <Flex align="center" gap="2">
                    <LockClosedIcon width={14} height={14} />
                    <Text size="1" color="gray">Locked field</Text>
                  </Flex>
                </Flex>
              </Box>
            </ScrollArea>
          </Box>

          {/* Footer Actions */}
          <Flex gap="3" mt="4" justify="end" pt="3" style={{ borderTop: '1px solid var(--gray-6)' }}>
            <Dialog.Close>
              <Button variant="soft" onClick={onCancel}>
                Cancel
              </Button>
            </Dialog.Close>
            <Button onClick={onConfirm} color="green">
              Apply {totalChanges > 0 && `(${totalChanges} change${totalChanges !== 1 ? 's' : ''})`}
            </Button>
          </Flex>
        </Flex>
      </Dialog.Content>
    </Dialog.Root>
  );
}
