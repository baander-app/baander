import { Badge, Button, Dialog, Flex, IconButton, ScrollArea, Separator, Text } from '@radix-ui/themes';
import { Cross2Icon, ValueNoneIcon } from '@radix-ui/react-icons';
import { useQueueManager } from '../../hooks/use-queue-manager';
import { usePlayerCurrentSongPublicId, usePlayerQueue } from '../../store/utilities';
import { MediaType } from '@/app/models/media-type';
import styles from './queue-modal.module.scss';

interface QueueModalProps {
  isOpen?: boolean;
  onClose?: () => void;
}

const QUEUE_TYPE_COLORS: Record<MediaType, string> = {
  [MediaType.MUSIC]: 'var(--purple-9)',
  [MediaType.AUDIOBOOK]: 'var(--orange-9)',
  [MediaType.PODCAST]: 'var(--blue-9)',
};

const QUEUE_TYPE_LABELS: Record<MediaType, string> = {
  [MediaType.MUSIC]: 'Music',
  [MediaType.AUDIOBOOK]: 'Audiobooks',
  [MediaType.PODCAST]: 'Podcasts',
};

export function QueueModal({isOpen, onClose}: QueueModalProps) {
  const queue = usePlayerQueue();
  const currentSongPublicId = usePlayerCurrentSongPublicId();
  const {
    removeFromQueue,
    clearQueue,
    playAtIndex,
    getQueueType,
  } = useQueueManager();

  const queueType = getQueueType();

  const handleRemoveSong = (publicId: string, index: number) => {
    removeFromQueue(index);
  };

  const handleClearQueue = () => {
    clearQueue();
    onClose?.();
  };

  const handleSongClick = (index: number) => {
    playAtIndex(index);
  };

  return (
    <Dialog.Root open={isOpen} onOpenChange={(open) => !open && onClose?.()}>
      <Dialog.Content
        className={styles.dialogContent}
        style={{
          maxWidth: 700,
          maxHeight: '80vh',
          backgroundColor: 'var(--color-background)',
          border: '1px solid var(--gray-6)',
          borderRadius: '8px',
        }}
      >
        {/* Header */}
        <Flex justify="between" align="center" p="4">
          <Flex direction="column" gap="1">
            <Flex align="center" gap="3">
              <Dialog.Title>
                <Text size="5" weight="bold">
                  {QUEUE_TYPE_LABELS?.[queueType]} Queue
                </Text>
              </Dialog.Title>
              <Badge
                color="gray"
                variant="surface"
                mb="4"
                size="3"
                style={{
                  backgroundColor: QUEUE_TYPE_COLORS[queueType],
                  color: 'white',
                }}
              >
                {queue.length}
              </Badge>
            </Flex>
            <Dialog.Description>
              <Text size="2" color="gray">
                Drag to reorder songs in the queue
              </Text>
            </Dialog.Description>
          </Flex>
          <Dialog.Close>
            <IconButton variant="ghost" aria-label="Close dialog">
              <Cross2Icon width={18} height={18}/>
            </IconButton>
          </Dialog.Close>
        </Flex>

        <Separator size="1" style={{backgroundColor: 'var(--gray-6)'}}/>

        {/* Queue List */}
        <ScrollArea style={{height: '50vh'}}>
          {queue.length > 0 ? (
            <div className={styles.queueList}>
              {queue.map((song, index) => {
                const isCurrentSong = song.publicId === currentSongPublicId;

                return (
                  <Flex
                    key={song.publicId}
                    align="center"
                    justify="between"
                    p="3"
                    className={`${styles.queueItem} ${isCurrentSong ? styles.queueItemCurrent : ''}`}
                    style={{
                      backgroundColor: isCurrentSong ? 'var(--accent-2)' : undefined,
                      borderLeft: isCurrentSong ? `3px solid ${QUEUE_TYPE_COLORS[queueType]}` : '3px solid transparent',
                    }}
                  >
                    <Flex
                      align="center"
                      gap="3"
                      style={{flex: 1, cursor: 'pointer'}}
                      onClick={() => handleSongClick(index)}
                    >
                      <Text
                        size="2"
                        color="gray"
                        style={{minWidth: 24, textAlign: 'center'}}
                      >
                        {index + 1}
                      </Text>
                      <Flex direction="column" gap="1" style={{flex: 1}}>
                        <Text
                          size="2"
                          weight={isCurrentSong ? 'bold' : 'regular'}
                        >
                          {song.title}
                        </Text>
                        {song.artist && (
                          <Text size="1" color="gray">
                            {song.artist}
                          </Text>
                        )}
                      </Flex>
                    </Flex>
                    <Button
                      size="1"
                      variant="ghost"
                      color="red"
                      aria-label="Remove from queue"
                      onClick={(e) => {
                        e.stopPropagation();
                        handleRemoveSong(song.publicId, index);
                      }}
                    >
                      Remove
                    </Button>
                  </Flex>
                );
              })}
            </div>
          ) : (
            <Flex
              direction="column"
              align="center"
              justify="center"
              gap="3"
              style={{height: '100%', minHeight: 200}}
            >
              <ValueNoneIcon width={48} height={48} style={{opacity: 0.3}}/>
              <Text size="4" color="gray" weight="medium">
                Queue is empty
              </Text>
              <Text size="2" color="gray">
                Add songs to start building your queue
              </Text>
            </Flex>
          )}
        </ScrollArea>

        {/* Footer */}
        {queue.length > 0 && (
          <>
            <Separator size="1" style={{backgroundColor: 'var(--gray-6)'}}/>
            <Flex justify="end" gap="2" p="4">
              <Button
                variant="surface"
                color="red"
                onClick={handleClearQueue}
              >
                Clear Queue
              </Button>
              <Dialog.Close>
                <Button variant="soft">Close</Button>
              </Dialog.Close>
            </Flex>
          </>
        )}
      </Dialog.Content>
    </Dialog.Root>
  );
}
