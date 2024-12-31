import { Box } from '@mantine/core';
import { MovieList } from '@/features/library-movies/components/movie-list/movie-list.tsx';

export function Overview() {

  return (
    <Box>
      <MovieList/>
    </Box>
  )
}