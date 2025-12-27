import { useState } from 'react';
import {
  AlertDialog,
  Flex,
  Text,
  Button,
  Checkbox,
} from '@radix-ui/themes';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { MediaType } from '@/app/models/media-type';
import styles from './queue-mixing-warning-dialog.module.scss';

interface QueueMixingWarningDialogProps {
  isOpen: boolean;
  sourceType: MediaType;
  targetType: MediaType;
  onConfirm: (rememberChoice: boolean) => void;
  onCancel: () => void;
}

const MEDIA_TYPE_LABELS: Record<MediaType, string> = {
  [MediaType.MUSIC]: 'music',
  [MediaType.AUDIOBOOK]: 'audiobooks',
  [MediaType.PODCAST]: 'podcasts',
};

export function QueueMixingWarningDialog({
  isOpen,
  sourceType,
  targetType,
  onConfirm,
  onCancel,
}: QueueMixingWarningDialogProps) {
  const [rememberChoice, setRememberChoice] = useState(false);

  const handleConfirm = () => {
    onConfirm(rememberChoice);
  };

  const handleSwitchQueue = () => {
    // Signal to switch queue type instead
    onConfirm(rememberChoice);
  };

  return (
    <AlertDialog.Root open={isOpen}>
      <AlertDialog.Content
        style={{
          maxWidth: 450,
          backgroundColor: 'var(--color-background)',
          border: '1px solid var(--orange-7)',
          borderRadius: '8px',
        }}
      >
        <Flex direction="column" gap="4" p="4">
          {/* Title with Icon */}
          <Flex align="center" gap="3">
            <InfoCircledIcon
              color="var(--orange-7)"
              width={24}
              height={24}
            />
            <AlertDialog.Title>
              <Text size="5" weight="bold">
                Mix Queue Types?
              </Text>
            </AlertDialog.Title>
          </Flex>

          {/* Description */}
          <AlertDialog.Description>
            <Text size="3" color="gray">
              You're about to add {MEDIA_TYPE_LABELS[sourceType]} content to a{' '}
              {MEDIA_TYPE_LABELS[targetType]} queue.
            </Text>
          </AlertDialog.Description>

          {/* Info Box */}
          <Flex
            direction="column"
            gap="2"
            p="3"
            style={{
              backgroundColor: 'var(--orange-2)',
              border: '1px solid var(--orange-5)',
              borderRadius: '6px',
            }}
          >
            <Text size="2" weight="bold" color="orange">
              What this means:
            </Text>
            <Text size="2" color="gray">
              • Different media types may have different playback behaviors
            </Text>
            <Text size="2" color="gray">
              • Queue features like shuffle may not work as expected
            </Text>
            <Text size="2" color="gray">
              • You can clear the queue at any time to start fresh
            </Text>
          </Flex>

          {/* Remember Choice Checkbox */}
          <Flex align="center" gap="2">
            <Checkbox
              checked={rememberChoice}
              onCheckedChange={(checked) => setRememberChoice(checked === true)}
            />
            <Text
              size="2"
              as="label"
              style={{ cursor: 'pointer' }}
              onClick={() => setRememberChoice(!rememberChoice)}
            >
              Don't ask again for this session
            </Text>
          </Flex>

          {/* Action Buttons */}
          <Flex direction="column" gap="2">
            <Button
              color="orange"
              onClick={handleConfirm}
            >
              Add Anyway (Advanced Mode)
            </Button>
            <Button
              variant="soft"
              onClick={onCancel}
            >
              Cancel
            </Button>
          </Flex>
        </Flex>
      </AlertDialog.Content>
    </AlertDialog.Root>
  );
}
