import { useSongServiceSongsShow } from '@/api-client/queries';
import { Box, NumberInput, SimpleGrid, Text, Textarea, TextInput } from '@mantine/core';
import { DateTime } from '@/components/dates/date-time.tsx';

export interface SongDetailProps {
  publicId: string;
}

export function SongDetail({publicId}: SongDetailProps) {
  const {data} = useSongServiceSongsShow({library: 'music', song: publicId});


  if (!data) return (
    <>Loading</>
  );
  return (
    <>
      <SimpleGrid cols={2}>
        <Box>
          <TextInput
            label="Title"
            defaultValue={data.title}
          />

          <NumberInput
            label="Year"
            defaultValue={data.year ?? ''}
            allowNegative={false}
            min={0}
            max={9999}
          />

          <NumberInput
            label="Disc number"
            defaultValue={data.disc ?? ''}
            allowNegative={false}
            min={0}
            max={9999}
          />

          <NumberInput
            label="Track number"
            defaultValue={data.track ?? ''}
            allowNegative={false}
            min={0}
            max={9999}
          />
          <Textarea
            label="Comment"
            defaultValue={data.comment ?? ''}
          />
        </Box>

        <Box>
          <Text>{data.public_id}</Text>
          <Text>{data.path}</Text>
          <Text>{data.durationHuman}</Text>
          <Text>{data.sizeHuman}</Text>
          <Text><DateTime time={data.createdAt!} /></Text>
          <Text>{data.updatedAt}</Text>
        </Box>
      </SimpleGrid>
    </>
  );
}