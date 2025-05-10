import { useSongServiceGetApiLibrariesByLibrarySongsByPublicId } from '@/api-client/queries';
import {
  Box,
  Callout,
  Grid,
  Spinner,
  Text,
  TextArea,
  TextField,
} from '@radix-ui/themes';
import { DateTime } from '@/ui/dates/date-time.tsx';
import { useEffect, useState } from 'react';

export interface SongDetailProps {
  publicId: string;
}

export function SongDetail({ publicId }: SongDetailProps) {
  const { data, error, isLoading } = useSongServiceGetApiLibrariesByLibrarySongsByPublicId({ library: 'music', publicId });

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

  if (isLoading) return <Spinner/>;

  if (error) {
    return (
      <Callout.Root color="red">
        <Callout.Text>{error?.message}</Callout.Text>
      </Callout.Root>
    );
  }

  if (!data) return <Text>No data available</Text>;

  const { public_id, path, durationHuman, sizeHuman, createdAt, updatedAt } = data;

  return (
    <Grid columns="2">
      <Box>
        <TextField.Root>
          <label>Title</label>
          <input
            type="text"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
          />
        </TextField.Root>


        <TextField.Root>
          <label>Year</label>
          <input
            type="number"
            value={year}
            onChange={(e) => setYear(e.target.value)}
            min={0}
            max={9999}
          />
        </TextField.Root>

        <TextField.Root>
          <label>Disc number</label>

          <input
            type="number"
            value={disc}
            onChange={(e) => setDisc(e.target.value)}
            min={0}
            max={9999}
          />
        </TextField.Root>

        <TextField.Root>
          <label>Track number</label>

          <input
            type="number"
            value={track}
            onChange={(e) => setTrack(e.target.value)}
            min={0}
            max={9999}
          />
        </TextField.Root>

        <TextField.Root>
          <label>Comment</label>

          <TextArea
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
        </TextField.Root>
      </Box>

      <Box>
        <Text>{public_id}</Text>
        <Text>{path}</Text>
        <Text>{durationHuman}</Text>
        <Text>{sizeHuman}</Text>
        <Text><DateTime date={createdAt!}/></Text>
        <Text>{updatedAt}</Text>
      </Box>
    </Grid>
  );
}