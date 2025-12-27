import { SongTable } from '@/app/components/song-table/song-table';
import { useAppDispatch } from '@/app/store/hooks';
import { createNotification } from '@/app/store/notifications/notifications-slice';
import { usePlayerActions } from '@/app/modules/library-music-player/store';
import { Iconify } from '@/app/ui/icons/iconify';
import { motion } from 'motion/react';
import { Badge, Box, Button, Dialog, Flex, Text } from '@radix-ui/themes';
import { useCallback, useState } from 'react';
import { SongResource } from '@/app/libs/api-client/gen/models';
import { PlaylistEditor } from '@/app/modules/library-music-playlists/components/playlist-editor/playlist-editor';
import { EditSmartPlaylistRules } from '@/app/modules/library-music-playlists/components/edit-smart-playlist-rules/edit-smart-playlist-rules';
import styles from './playlist-detail.module.scss';
import {
  usePlaylistDestroy,
  usePlaylistRemoveSong,
  usePlaylistReorder,
  usePlaylistShow,
  usePlaylistSmartSync,
} from '@/app/libs/api-client/gen/endpoints/playlist/playlist';

interface PlaylistDetailProps {
  playlistId: string;
  librarySlug: string;
}

export function PlaylistDetail({ playlistId, librarySlug: _librarySlug }: PlaylistDetailProps) {
  const dispatch = useAppDispatch();
  const { setQueueAndPlay } = usePlayerActions();
  const { data: playlist, isLoading, refetch } = usePlaylistShow(playlistId, {
    relations: 'songs,songs.artists,songs.album,songs.genres,songs.cover,cover,statistics',
  });
  const removeMutation = usePlaylistRemoveSong();
  const reorderMutation = usePlaylistReorder();
  const syncMutation = usePlaylistSmartSync();
  const destroyMutation = usePlaylistDestroy();

  const [editDialogOpen, setEditDialogOpen] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [editRulesDialogOpen, setEditRulesDialogOpen] = useState(false);

  const isSmart = playlist?.isSmart === "1";
  const isOwner = true;
  const songs = playlist?.songs ?? [];

  const handlePlayAll = useCallback(() => {
    if (songs.length === 0) return;
    setQueueAndPlay(songs, songs[0].publicId);
  }, [songs, setQueueAndPlay]);

  const handleShuffle = useCallback(() => {
    if (songs.length === 0) return;
    const shuffled = [...songs].sort(() => Math.random() - 0.5);
    setQueueAndPlay(shuffled, shuffled[0].publicId);
  }, [songs, setQueueAndPlay]);

  const handleRemoveSong = useCallback((song: SongResource) => {
    if (!playlist) return;

    removeMutation.mutate(
      {
        playlist: playlist.publicId,
        song: song.publicId,
      },
      {
        onSuccess: () => {
          dispatch(
            createNotification({
              title: 'Success',
              message: 'Song removed from playlist',
              type: 'success',
              toast: true,
            })
          );
          refetch();
        },
        onError: (error: any) => {
          dispatch(
            createNotification({
              title: 'Error',
              message: error.response?.data?.message || 'Failed to remove song',
              type: 'error',
              toast: true,
            })
          );
        },
      }
    );
  }, [playlist, removeMutation, dispatch, refetch]);

  const handleReorder = useCallback((oldIndex: number, newIndex: number) => {
    if (!playlist) return;

    // API expects number[] but we only have publicId (string)
    // Type assertion used as the API might accept strings or this needs backend fix
    const songIds = songs.map(s => s.publicId);
    const reorderedIds = [...songIds];
    const [moved] = reorderedIds.splice(oldIndex, 1);
    reorderedIds.splice(newIndex, 0, moved);

    reorderMutation.mutate(
      {
        playlist: playlist.publicId,
        data: { song_ids: reorderedIds as any },
      },
      {
        onSuccess: () => {
          refetch();
        },
        onError: (error: any) => {
          dispatch(
            createNotification({
              title: 'Error',
              message: error.response?.data?.message || 'Failed to reorder playlist',
              type: 'error',
              toast: true,
            })
          );
          refetch(); // Refetch to restore order
        },
      }
    );
  }, [playlist, reorderMutation, dispatch, refetch]);

  const handleSyncSmart = useCallback(() => {
    if (!playlist) return;

    syncMutation.mutate(
      { playlist: playlist.publicId },
      {
        onSuccess: () => {
          dispatch(
            createNotification({
              title: 'Success',
              message: 'Smart playlist synced',
              type: 'success',
              toast: true,
            })
          );
          refetch();
        },
        onError: (error: any) => {
          dispatch(
            createNotification({
              title: 'Error',
              message: error.response?.data?.message || 'Failed to sync playlist',
              type: 'error',
              toast: true,
            })
          );
        },
      }
    );
  }, [playlist, syncMutation, dispatch, refetch]);

  const handleDelete = useCallback(() => {
    if (!playlist) return;

    destroyMutation.mutate(
      { playlist: playlist.publicId },
      {
        onSuccess: () => {
          dispatch(
            createNotification({
              title: 'Success',
              message: 'Playlist deleted successfully',
              type: 'success',
              toast: true,
            })
          );
          setDeleteDialogOpen(false);
          // TODO: Navigate back to playlists list
          window.history.back();
        },
        onError: (error: any) => {
          dispatch(
            createNotification({
              title: 'Error',
              message: error.response?.data?.message || 'Failed to delete playlist',
              type: 'error',
              toast: true,
            })
          );
        },
      }
    );
  }, [playlist, destroyMutation, dispatch]);

  if (isLoading) {
    return (
      <Box className={styles.detailPanel}>
        <Text>Loading...</Text>
      </Box>
    );
  }

  if (!playlist) {
    return (
      <Box className={styles.detailPanel}>
        <Text>Playlist not found</Text>
      </Box>
    );
  }

  return (
    <motion.div
      className={styles.detailPanel}
      layout
      initial={{ opacity: 0, scale: 0.3 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{
        duration: 0.3,
        ease: [0, 0.71, 0.2, 1.01],
      }}
    >
      {/* Header */}
      <Box className={styles.header}>
        <Flex direction="column" gap="4">
          <Flex align="center" gap="2">
            <Text size="8" weight="bold">
              {playlist.name}
            </Text>
            {isSmart && (
              <Badge color="blue">
                <Iconify icon="carbon:rule" width={14} height={14} />
                Smart
              </Badge>
            )}
            {playlist.isPublic === "1" && (
              <Badge color="green">Public</Badge>
            )}
            {playlist.isCollaborative === "1" && (
              <Badge color="gray">
                <Iconify icon="ph:users" width={14} height={14} />
                Collaborative
              </Badge>
            )}
            {isOwner && (
              <Flex gap="2" ml="auto">
                <Button size="1" variant="soft" onClick={() => setEditDialogOpen(true)}>
                  <Iconify icon="ph:pencil-simple" width={14} height={14} />
                  Edit
                </Button>
                <Button size="1" color="red" variant="soft" onClick={() => setDeleteDialogOpen(true)}>
                  <Iconify icon="ph:trash" width={14} height={14} />
                  Delete
                </Button>
              </Flex>
            )}
          </Flex>

          {playlist.description && (
            <Text color="gray" size="2">
              {playlist.description}
            </Text>
          )}

          <Flex align="center" gap="4">
            <Text size="1" color="gray">
              {songs.length} songs
            </Text>
            <Text size="1" color="gray">
              • Created by {playlist.owner?.name || 'Unknown'}
            </Text>
          </Flex>

          {playlist.statistics && (
            <Flex align="center" gap="4" mt="2">
              <Flex align="center" gap="1">
                <Iconify icon="ph:eye" width={14} height={14} />
                <Text size="1" color="gray">
                  {playlist.statistics.views}
                </Text>
              </Flex>
              <Flex align="center" gap="1">
                <Iconify icon="ph:play-circle" width={14} height={14} />
                <Text size="1" color="gray">
                  {playlist.statistics.plays}
                </Text>
              </Flex>
              <Flex align="center" gap="1">
                <Iconify icon="ph:share-network" width={14} height={14} />
                <Text size="1" color="gray">
                  {playlist.statistics.shares}
                </Text>
              </Flex>
              <Flex align="center" gap="1">
                <Iconify icon="ph:heart" width={14} height={14} />
                <Text size="1" color="gray">
                  {playlist.statistics.favorites}
                </Text>
              </Flex>
            </Flex>
          )}
        </Flex>
      </Box>

      {/* Action Buttons */}
      <Flex gap="2" className={styles.actions}>
        <Button size="1" onClick={handlePlayAll}>
          <Iconify icon="ph:play-circle" width={16} height={16} />
          Play All
        </Button>
        <Button size="1" variant="soft" onClick={handleShuffle}>
          <Iconify icon="ph:shuffle" width={16} height={16} />
          Shuffle
        </Button>
        {isSmart && (
          <>
            <Button size="1" variant="soft" onClick={handleSyncSmart} disabled={syncMutation.isPending}>
              <Iconify icon="ph:arrows-clockwise" width={16} height={16} />
              {syncMutation.isPending ? 'Syncing...' : 'Sync Now'}
            </Button>
            {isOwner && (
              <Button size="1" variant="soft" onClick={() => setEditRulesDialogOpen(true)}>
                <Iconify icon="ph:sliders-horizontal" width={16} height={16} />
                Edit Rules
              </Button>
            )}
          </>
        )}
      </Flex>

      {/* Songs Table */}
      <SongTable
        songs={songs}
        reorderable={!isSmart}
        onReorder={handleReorder}
        contextMenuActions={{
          onRemoveFromPlaylist: handleRemoveSong,
        }}
        className={styles.songTable}
      />

      {/* Edit Dialog */}
      <Dialog.Root open={editDialogOpen} onOpenChange={setEditDialogOpen}>
        <Dialog.Content style={{ maxWidth: 500 }}>
          <Dialog.Title>Edit Playlist</Dialog.Title>
          <PlaylistEditor
            playlist={playlist}
            onSuccess={() => {
              refetch();
              setEditDialogOpen(false);
            }}
          />
        </Dialog.Content>
      </Dialog.Root>

      {/* Edit Rules Dialog */}
      {isSmart && (
        <Dialog.Root open={editRulesDialogOpen} onOpenChange={setEditRulesDialogOpen}>
          <Dialog.Content style={{ maxWidth: 700 }}>
            <Dialog.Title>Edit Smart Playlist Rules</Dialog.Title>
            <Dialog.Description size="2" color="gray" mb="4">
              Configure rules to automatically populate "{playlist.name}"
            </Dialog.Description>
            <EditSmartPlaylistRules
              playlist={playlist}
              onSuccess={() => {
                refetch();
                setEditRulesDialogOpen(false);
              }}
              onCancel={() => setEditRulesDialogOpen(false)}
            />
          </Dialog.Content>
        </Dialog.Root>
      )}

      {/* Delete Confirmation Dialog */}
      <Dialog.Root open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <Dialog.Content style={{ maxWidth: 400 }}>
          <Dialog.Title>Delete Playlist</Dialog.Title>
          <Dialog.Description size="2" mb="4">
            Are you sure you want to delete "{playlist.name}"? This action cannot be undone.
          </Dialog.Description>
          <Flex gap="3" mt="4">
            <Button variant="soft" onClick={() => setDeleteDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              color="red"
              onClick={handleDelete}
              disabled={destroyMutation.isPending}
            >
              {destroyMutation.isPending ? 'Deleting...' : 'Delete Playlist'}
            </Button>
          </Flex>
        </Dialog.Content>
      </Dialog.Root>
    </motion.div>
  );
}
