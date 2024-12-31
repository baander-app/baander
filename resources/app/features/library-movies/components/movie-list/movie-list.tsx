import { forwardRef } from 'react';
import { VirtuosoGrid, Components } from 'react-virtuoso';
import { MediaItem } from '@/components/media-library/media-item/media-item.tsx';
import { MovieResource } from '@/features/library-movies/modes.ts';
import { movieResources } from '@/features/library-movies/mock.ts';

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


  return (
    <>

      <VirtuosoGrid
        style={{ display: 'flex', flexWrap: 'wrap', flex: 1 }}
        data={movieResources}
        totalCount={movieResources.length}
        components={gridComponents}
        itemContent={(index, data) => <MediaItem key={index} item={data}/>}
      />
    </>
  );
}