import React from 'react';
import { SongTable } from '@/components/song-table/song-table';
import { usePathParam } from '@/hooks/use-path-param';
import { usePlaylistServiceGetApiPlaylistsByPlaylist } from '@/api-client/queries';
import { MusicPlaylistParams } from '@/modules/library-music-playlists/_routes.tsx';
import styles from './playlist.module.scss';

export function PlayList() {
  const { playlistId } = usePathParam<MusicPlaylistParams>();
  const {
    data,
  } = usePlaylistServiceGetApiPlaylistsByPlaylist({
    playlist: playlistId,
  });

  return (
    <SongTable
      songs={data?.songs || []}
      title={data?.name}
      description={data?.description}
      className={styles.playlistTable}
    />
  );
}
