import { Cover } from '@/modules/library-music/components/artwork/cover';
import { useAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbum } from '@/api-client/queries';
import { SongResource } from '@/api-client/requests';
import { Text, Card, Flex, Box, ScrollArea, Skeleton } from '@radix-ui/themes';
import { AlertLoadingError } from '@/ui/alerts/alert-loading-error.tsx';
import { useAppDispatch } from '@/store/hooks.ts';
import { setQueueAndSong } from '@/store/music/music-player-slice.ts';
import { TrackRow } from '@/ui/music/track-row/track-row.tsx';

import styles from './album-detail.module.scss';
import { usePathParam } from '@/hooks/use-path-param.ts';
import { LibraryParams } from '@/modules/library-music/routes/_routes.tsx';
import { generateBlurhashBackgroundImage } from '@/libs/blurhash/generate-bg-image.ts';

interface AlbumDetailProps extends React.HTMLAttributes<HTMLDivElement> {
  albumSlug: string;
}

export function AlbumDetail({ albumSlug, ...rest }: AlbumDetailProps) {
  const { library } = usePathParam<LibraryParams>();
  const { data, isLoadingError, refetch } = useAlbumServiceGetApiLibrariesByLibraryAlbumsByAlbum({
    album: albumSlug,
    library: library,
  });

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
                    <Text >{data.artists.map(x => x.name).join(', ')}</Text>
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
  const dispatch = useAppDispatch();

  const onSongClick = (song: SongResource, songs: SongResource[]) => {
    dispatch(setQueueAndSong({
      queue: songs,
      playPublicId: song.public_id,
    }));
  };

  const rows = songs.map((song) => (
    <TrackRow
      className={styles.trackRow}
      song={song}
      key={song.public_id}
      onClick={() => {
        console.log('row clicked');
        onSongClick(song, songs);
      }}
    />
  ));

  return (
    <>
      <ScrollArea>
        <table >
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


function AlbumDetailSkeleton() {
  return (
    <Card>
      <Flex>
        <Box p="sm">
          <Skeleton height="180px" width="180px" />
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