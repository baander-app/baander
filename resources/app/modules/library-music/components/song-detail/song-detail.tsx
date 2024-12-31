import { useSongServiceSongsShow } from '@/api-client/queries';
import { Box, NumberInput, SimpleGrid, Text, Textarea, TextInput, Loader, Alert, SimpleGridProps } from '@mantine/core';
import { DateTime } from '@/ui/dates/date-time.tsx';
import { useEffect, useState } from 'react';

export interface SongDetailProps extends SimpleGridProps {
  publicId: string;
}

export function SongDetail({ publicId, ...rest }: SongDetailProps) {
  const { data, error, isLoading } = useSongServiceSongsShow({ library: 'music', publicId });

  const [title, setTitle] = useState('');
  const [year, setYear] = useState<number | ''>('');
  const [disc, setDisc] = useState<number | ''>('');
  const [track, setTrack] = useState<number | ''>('');
  const [comment, setComment] = useState('');

  // Synchronize state with data
  useEffect(() => {
    if (data) {
      setTitle(data.title || '');
      setYear(data.year ?? '');
      setDisc(data.disc ?? '');
      setTrack(data.track ?? '');
      setComment(data.comment ?? '');
    }
  }, [data]);

  if (isLoading) return <Loader />;

  if (error) return <Alert color="red">{error?.message}</Alert>;

  if (!data) return <Text>No data available</Text>;

  const { public_id, path, durationHuman, sizeHuman, createdAt, updatedAt } = data;

  return (
    <SimpleGrid cols={2} {...rest}>
      <Box>
        <TextInput
          label="Title"
          value={title}
          onChange={(event) => setTitle(event.currentTarget.value)}
        />

        <NumberInput
          label="Year"
          value={year}
          onChange={(value) => setYear(value)}
          allowNegative={false}
          min={0}
          max={9999}
        />

        <NumberInput
          label="Disc number"
          value={disc}
          onChange={(value) => setDisc(value)}
          allowNegative={false}
          min={0}
          max={9999}
        />

        <NumberInput
          label="Track number"
          value={track}
          onChange={(value) => setTrack(value)}
          allowNegative={false}
          min={0}
          max={9999}
        />

        <Textarea
          label="Comment"
          value={comment}
          onChange={(event) => setComment(event.currentTarget.value)}
        />
      </Box>

      <Box>
        <Text>{public_id}</Text>
        <Text>{path}</Text>
        <Text>{durationHuman}</Text>
        <Text>{sizeHuman}</Text>
        <Text><DateTime time={createdAt!} /></Text>
        <Text>{updatedAt}</Text>
      </Box>
    </SimpleGrid>
  );
}