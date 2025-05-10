import { Box, Text } from '@radix-ui/themes';
import { MovieResource } from '@/modules/library-movies/modes.ts';

export interface MediaItemProps {
  item: MovieResource;
}
export function MediaItem({ item }: MediaItemProps) {
  return (
    <Box>
      <img src={item.poster ?? 'https://placehold.co/200x400'} />

      <Text>{item.title}</Text>
    </Box>
  )
}