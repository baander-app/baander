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
import { useSongsShow } from '@/libs/api-client/gen/endpoints/song/song.ts';

export interface SongDetailProps {
  publicId: string;
}

export function SongDetail({ publicId }: SongDetailProps) {
  const { data, error, isLoading } = useSongsShow('music', publicId);

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
        <Callout.Text>{
          // @ts-expect-error
          error?.message
        }</Callout.Text>
      </Callout.Root>
    );
  }

  if (!data) return <Text>No data available</Text>;

  const { path, durationHuman, sizeHuman, createdAt, updatedAt } = data;

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
            // @ts-expect-error
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
            // @ts-expect-error
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
            // @ts-expect-error
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
        <Text>{publicId}</Text>
        <Text>{path}</Text>
        <Text>{durationHuman}</Text>
        <Text>{sizeHuman}</Text>
        <Text><DateTime date={createdAt!}/></Text>
        <Text>{updatedAt}</Text>
      </Box>
    </Grid>
  );
}