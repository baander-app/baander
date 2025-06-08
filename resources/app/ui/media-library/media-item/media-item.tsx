import { Box, Text } from '@radix-ui/themes';
import { MovieResource } from '@/api-client/requests';

export interface MediaItemProps {
  item: MovieResource;
}
export function MediaItem({ item }: MediaItemProps) {
  return (
    <Box>
      <img src={'https://placehold.co/180x260'}  alt=""/>

      <Text>{item.title}</Text>
    </Box>
  )
}