import { Box } from '@radix-ui/themes';
import { MovieList } from '@/app/modules/library-movies/components/movie-list/movie-list.tsx';

export function Overview() {

  return (
    <Box>
      <MovieList/>
    </Box>
  )
}