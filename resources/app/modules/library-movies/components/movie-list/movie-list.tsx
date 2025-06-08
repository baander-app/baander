import { forwardRef } from 'react';
import { VirtuosoGrid, Components } from 'react-virtuoso';
import { MediaItem } from '@/ui/media-library/media-item/media-item.tsx';
import { useMovieServiceGetApiLibrariesByLibraryMovies } from '@/api-client/queries';
import { MovieResource } from '@/api-client/requests';

// Ensure that this stays out of the component,
// Otherwise the grid will remount with each render due to new component instances.
const gridComponents: Components<MovieResource> = {
    List: forwardRef(({ style, children, ...props }, ref) => (
      <div
        ref={ref}
        {...props}
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          ...style,
        }}
      >
        {children}
      </div>
    )),
    Item: ({ children, ...props }) => (
      <div
        {...props}
        style={{
          padding: '0.5rem',
          width: '33%',
          display: 'flex',
          flex: 'none',
          alignContent: 'stretch',
          boxSizing: 'border-box',
        }}
      >
        {children}
      </div>
    ),
  }
;

export function MovieList() {
  const {
    data,
  } = useMovieServiceGetApiLibrariesByLibraryMovies({
    library: 'movies'
  })


  return (
    <>

      <VirtuosoGrid
        style={{ display: 'flex', flexWrap: 'wrap', flex: 1, overflowY: 'unset' }}
        data={data?.data ?? []}
        totalCount={data?.meta.total}
        // @ts-expect-error
        components={gridComponents}
        itemContent={(index, data) => <MediaItem key={index} item={data}/>}
      />
    </>
  );
}