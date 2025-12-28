import { Badge, Box, Flex, Text, Button, Card, Avatar } from '@radix-ui/themes';
import { QualityBadge } from './quality-badge';
import { Release } from '@/app/libs/api-client/gen/models/release';
import { AppHttpIntegrationsMusicBrainzModelsRelease } from '@/app/libs/api-client/gen/models/appHttpIntegrationsMusicBrainzModelsRelease';

export type MetadataItem = Release | AppHttpIntegrationsMusicBrainzModelsRelease;

export interface MetadataCardProps {
  data: MetadataItem;
  source: 'musicbrainz' | 'discogs';
  qualityScore: number;
  onPreview?: () => void;
  onApply?: () => void;
  isSelected?: boolean;
  onSelect?: () => void;
}

function isDiscogsRelease(item: MetadataItem): item is Release {
  return 'title' in item && 'uri' in item;
}

function isMusicBrainzRelease(item: MetadataItem): item is AppHttpIntegrationsMusicBrainzModelsRelease {
  return 'id' in item && 'title' in item && !('uri' in item);
}

function getSourceColor(source: 'musicbrainz' | 'discogs') {
  return source === 'musicbrainz' ? 'blue' : 'orange';
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

function getId(item: MetadataItem): string {
  if (isDiscogsRelease(item)) {
    return item.id?.toString() ?? '—';
  }

  if (isMusicBrainzRelease(item)) {
    return item.id ?? '—';
  }

  return '—';
}

export function MetadataCard({
  data,
  source,
  qualityScore,
  onPreview,
  onApply,
  isSelected,
  onSelect
}: MetadataCardProps) {
  const title = getTitle(data);
  const artists = formatArtists(data);
  const year = getYear(data);
  const id = getId(data);

  return (
    <Card>
      <Flex gap="3" align="center">
        {/* Selection checkbox */}
        {onSelect && (
          <Box>
            <input
              type="checkbox"
              checked={isSelected}
              onChange={onSelect}
              style={{ cursor: 'pointer' }}
              aria-label={`Select ${title}`}
            />
          </Box>
        )}

        {/* Thumbnail/Avatar */}
        <Avatar
          size="3"
          src={isDiscogsRelease(data) ? data.thumbnail : undefined}
          fallback={title.substring(0, 2).toUpperCase()}
          color="gray"
        />

        {/* Metadata info */}
        <Flex direction="column" gap="1" style={{ flexGrow: 1 }}>
          <Flex gap="2" align="center">
            <Text weight="bold" size="2">{title}</Text>
            <Badge color={getSourceColor(source)} size="1">
              {source}
            </Badge>
          </Flex>

          <Flex gap="4" align="center">
            <Text size="1" color="gray">
              {artists}
            </Text>
            <Text size="1" color="gray">
              {year}
            </Text>
            <Text size="1" color="gray">
              ID: {id}
            </Text>
            <QualityBadge score={qualityScore} />
          </Flex>
        </Flex>

        {/* Actions */}
        <Flex gap="2">
          {onPreview && (
            <Button size="1" variant="outline" onClick={onPreview}>
              Preview
            </Button>
          )}
          {onApply && (
            <Button size="1" onClick={onApply}>
              Apply
            </Button>
          )}
        </Flex>
      </Flex>
    </Card>
  );
}
