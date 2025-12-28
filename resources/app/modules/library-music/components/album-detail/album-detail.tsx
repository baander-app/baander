import { Cover } from '@/app/modules/library-music/components/artwork/cover';
import { Box, Card, Flex, ScrollArea, Skeleton, Text } from '@radix-ui/themes';
import { AlertLoadingError } from '@/app/ui/alerts/alert-loading-error.tsx';
import { usePlayerActions } from '@/app/modules/library-music-player/store';
import { TrackRow } from '@/app/ui/music/track-row/track-row.tsx';

import styles from './album-detail.module.scss';
import { usePathParam } from '@/app/hooks/use-path-param.ts';
import { LibraryParams } from '@/app/modules/library-music/routes/_routes.tsx';
import { generateBlurhashBackgroundImage } from '@/app/libs/blurhash/generate-bg-image.ts';
import { useCallback } from 'react';
import { useAlbumsShow } from '@/app/libs/api-client/gen/endpoints/album/album.ts';
import { SongResource } from '@/app/libs/api-client/gen/models';

interface AlbumDetailProps extends React.HTMLAttributes<HTMLDivElement> {
  albumSlug: string;
}

export function AlbumDetail({ albumSlug, ...rest }: AlbumDetailProps) {
  const { library } = usePathParam<LibraryParams>();
  const { data, isLoadingError, refetch } = useAlbumsShow(library, albumSlug);

  const genres = data?.genres?.map((genre) => genre.name).join(', ');
  const blurhash = data?.cover && generateBlurhashBackgroundImage(data.cover.blurhash, 128, 128);

  return (
    <Box {...rest}>
      {/*TODO: fix popin{isFetching && <AlbumDetailSkeleton/>}*/}
      {isLoadingError && <AlertLoadingError retry={async () => {
        await refetch();
      }}/>}

      {data && (
        <Card className={styles.card}>
          {blurhash && (
            <div
              className={styles.image}
              style={{
                backgroundImage: blurhash.backgroundUrl,
              }}
            />
          )}

          <div className={styles.content}>
            <div>
              <Flex align="stretch">
                <Box p="3">
                  <Cover imgSrc={data?.cover?.url} size={180}/>
                </Box>

                <Flex p="sm" align="start" direction="column" justify="center">
                  <Text size="6" weight="bold">{data?.title}</Text>
                  {data?.artists && (
                    <Text>{data.artists.map(x => x.name).join(', ')}</Text>
                  )}

                  <Text>{genres} - {data?.year}</Text>
                </Flex>

                <Box></Box>
              </Flex>

              <Flex>
                {data?.songs && <AlbumSongs title={data.title} coverUrl={data.cover?.url} songs={data.songs}/>}
              </Flex>
            </div>
          </div>
        </Card>
      )}
    </Box>
  );
}

interface AlbumSongProps {
  title: string;
  coverUrl?: string;
  songs: SongResource[];
}

function AlbumSongs({ songs }: AlbumSongProps) {
  const { setQueueAndPlay } = usePlayerActions();

  const onSongClick = useCallback((song: SongResource, songs: SongResource[]) => {
    console.group('onSongClick');
    console.log('song', song);
    console.log('songs', songs);
    console.groupEnd();

    const newQueue = [...songs];
    const index = newQueue.findIndex(x => x.publicId === song.publicId);
    newQueue.splice(0, 0, newQueue.splice(index, 1)[0]);

    setQueueAndPlay(newQueue, song.publicId);
  }, [setQueueAndPlay]);


  const rows = songs.map((song) => (
    <TrackRow
      className={styles.trackRow}
      song={song}
      key={song.publicId}
      onClick={() => {
        onSongClick(song, songs);
      }}
    />
  ));

  return (
    <>
      <ScrollArea>
        <table>
          <thead>
          <tr>
            <th>Track</th>
            <th>Title</th>
            <th>Duration</th>
          </tr>
          </thead>
          <tbody>{rows}</tbody>
        </table>
      </ScrollArea>
    </>
  );
}

// @ts-expect-error
function AlbumDetailSkeleton() {
  return (
    <Card>
      <Flex>
        <Box p="sm">
          <Skeleton height="180px" width="180px"/>
        </Box>

        <Box p="sm" width="100%">
          <Skeleton height="16px" mt="sm"/>
          <Flex>
            <Skeleton height="8px" mt="sm" width="50px"/>
            <Skeleton height="8px" mt="sm" ml="sm" width="50px"/>
          </Flex>
        </Box>
      </Flex>

      <Flex>
        <Skeleton height="16px"/>
        <Skeleton height="16px"/>
        <Skeleton height="16px"/>
        <Skeleton height="16px"/>
        <Skeleton height="16px"/>
        <Skeleton height="16px"/>
        <Skeleton height="16px"/>
      </Flex>
    </Card>
  );
}
