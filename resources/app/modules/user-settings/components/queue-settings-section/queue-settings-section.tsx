/**
 * Queue Settings Section Component
 * Allows users to configure queue behavior (simple/advanced mode, completion behavior, etc.)
 */

import {
  Box,
  Flex,
  Text,
  Select,
  Switch,
  Separator,
} from '@radix-ui/themes';
import { useSettingsStore } from '@/app/store/settings';
import { QueueMode, QueueCompletionBehavior } from '@/app/store/settings/settings-types';

export function QueueSettingsSection() {
  const settings = useSettingsStore();
  const updateSettings = useSettingsStore((state) => state.updateSettings);

  const queueSettings = settings.preferences.queue;
  const playbackSettings = settings.preferences.playback;

  const handleQueueModeChange = (value: string) => {
    updateSettings({
      ...settings,
      preferences: {
        ...settings.preferences,
        queue: {
          ...queueSettings,
          mode: value as QueueMode,
        },
      },
    });
  };

  const handleCompletionBehaviorChange = (value: string) => {
    updateSettings({
      ...settings,
      preferences: {
        ...settings.preferences,
        playback: {
          ...playbackSettings,
          queueCompletion: value as QueueCompletionBehavior,
        },
      },
    });
  };

  const handleAutoSwitchChange = (checked: boolean) => {
    updateSettings({
      ...settings,
      preferences: {
        ...settings.preferences,
        queue: {
          ...queueSettings,
          autoSwitch: checked,
        },
      },
    });
  };

  const handleRememberPositionChange = (checked: boolean) => {
    updateSettings({
      ...settings,
      preferences: {
        ...settings.preferences,
        queue: {
          ...queueSettings,
          rememberPosition: checked,
        },
      },
    });
  };

  const handleWarnOnReplaceChange = (checked: boolean) => {
    updateSettings({
      ...settings,
      preferences: {
        ...settings.preferences,
        queue: {
          ...queueSettings,
          warnOnQueueReplace: checked,
        },
      },
    });
  };

  return (
    <Box>
      <Text as="p" size="5" weight="bold" mb="4">
        Queue Settings
      </Text>

      {/* Queue Mode */}
      <Flex direction="column" gap="2" mb="6">
        <Text size="2" weight="bold" color="gray">
          Queue Mode
        </Text>
        <Select.Root
          value={queueSettings.mode}
          onValueChange={handleQueueModeChange}
        >
          <Select.Trigger aria-label="Queue mode" />
          <Select.Content>
            <Select.Item value={QueueMode.SIMPLE}>
              Simple Mode
            </Select.Item>
            <Select.Item value={QueueMode.ADVANCED}>
              Advanced Mode
            </Select.Item>
          </Select.Content>
        </Select.Root>
        <Text size="1" color="gray">
          {queueSettings.mode === QueueMode.SIMPLE
            ? 'Simple: Queues are isolated by media type (no mixing)'
            : 'Advanced: Allow mixing different media types with warnings'}
        </Text>
      </Flex>

      {/* Queue Completion Behavior */}
      <Flex direction="column" gap="2" mb="6">
        <Text size="2" weight="bold" color="gray">
          When Queue Ends
        </Text>
        <Select.Root
          value={playbackSettings.queueCompletion}
          onValueChange={handleCompletionBehaviorChange}
        >
          <Select.Trigger aria-label="Queue completion behavior" />
          <Select.Content>
            <Select.Item value={QueueCompletionBehavior.STOP}>
              Stop Playback
            </Select.Item>
            <Select.Item value={QueueCompletionBehavior.SHUFFLE}>
              Shuffle and Replay
            </Select.Item>
            <Select.Item value={QueueCompletionBehavior.PLAY_RANDOM}>
              Play Random Song
            </Select.Item>
          </Select.Content>
        </Select.Root>
        <Text size="1" color="gray">
          What happens when the queue finishes playing
        </Text>
      </Flex>

      <Separator size="1" my="4" style={{ backgroundColor: 'var(--gray-6)' }} />

      {/* Auto-Switch Queues */}
      <Flex justify="between" align="center" mb="4">
        <Flex direction="column" gap="1">
          <Text size="2" weight="medium">
            Auto-Switch Queues
          </Text>
          <Text size="1" color="gray">
            Automatically switch queues when navigating between library types
          </Text>
        </Flex>
        <Switch
          checked={queueSettings.autoSwitch}
          onCheckedChange={handleAutoSwitchChange}
        />
      </Flex>

      {/* Remember Position */}
      <Flex justify="between" align="center" mb="4">
        <Flex direction="column" gap="1">
          <Text size="2" weight="medium">
            Remember Position
          </Text>
          <Text size="1" color="gray">
            Save playback position for audiobooks and podcasts
          </Text>
        </Flex>
        <Switch
          checked={queueSettings.rememberPosition}
          onCheckedChange={handleRememberPositionChange}
        />
      </Flex>

      {/* Warn on Queue Replace */}
      <Flex justify="between" align="center">
        <Flex direction="column" gap="1">
          <Text size="2" weight="medium">
            Warn Before Replacing Queue
          </Text>
          <Text size="1" color="gray">
            Show confirmation before replacing a non-empty queue
          </Text>
        </Flex>
        <Switch
          checked={queueSettings.warnOnQueueReplace}
          onCheckedChange={handleWarnOnReplaceChange}
        />
      </Flex>
    </Box>
  );
}
