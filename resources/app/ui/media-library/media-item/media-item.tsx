import { Box, Image, Text } from '@mantine/core';
import { MovieResource } from '@/modules/library-movies/modes.ts';


export interface MediaItemProps {
  item: MovieResource;
}
export function MediaItem({ item }: MediaItemProps) {
  return (
    <Box>
      <Image src={item.poster ?? 'https://placehold.co/200x400'} />

      <Text>{item.title}</Text>
    </Box>
  )
}