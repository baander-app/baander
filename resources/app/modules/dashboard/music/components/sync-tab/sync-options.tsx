import { Flex, Text, Switch, TextField } from '@radix-ui/themes';
import type { SyncOptionsConfig } from './sync-tab';

export interface SyncOptionsProps {
  options: SyncOptionsConfig;
  onChange: (options: SyncOptionsConfig) => void;
}

export function SyncOptions({ options, onChange }: SyncOptionsProps) {
  const updateOption = <K extends keyof SyncOptionsConfig>(
    key: K,
    value: SyncOptionsConfig[K]
  ) => {
    onChange({ ...options, [key]: value });
  };

  return (
    <Flex direction="column" gap="4">
      <Text weight="bold">Sync Options</Text>

      <Flex align="center" justify="between">
        <Flex direction="column">
          <Text weight="medium">Force Update</Text>
          <Text size="2" color="gray">
            Re-sync items even if they already have metadata
          </Text>
        </Flex>
        <Switch
          checked={options.forceUpdate}
          onCheckedChange={(checked) => updateOption('forceUpdate', checked)}
        />
      </Flex>

      <Flex direction="column" gap="2">
        <Flex justify="between">
          <Text weight="medium">Batch Size</Text>
          <Text size="2" color="gray">
            {options.batchSize} items per batch
          </Text>
        </Flex>
        <Text size="2" color="gray">
          Number of items to process in each batch (1-100)
        </Text>
        <TextField.Root
          type="number"
          min={1}
          max={100}
          value={options.batchSize.toString()}
          onChange={(e) => {
            const value = parseInt(e.target.value, 10);
            if (!isNaN(value) && value >= 1 && value <= 100) {
              updateOption('batchSize', value);
            }
          }}
          size="3"
        />
      </Flex>

      <Flex align="center" justify="between">
        <Flex direction="column">
          <Text weight="medium">Include Songs</Text>
          <Text size="2" color="gray">
            Sync metadata for individual songs
          </Text>
        </Flex>
        <Switch
          checked={options.includeSongs}
          onCheckedChange={(checked) => updateOption('includeSongs', checked)}
        />
      </Flex>

      <Flex align="center" justify="between">
        <Flex direction="column">
          <Text weight="medium">Include Artists</Text>
          <Text size="2" color="gray">
            Sync metadata for artists
          </Text>
        </Flex>
        <Switch
          checked={options.includeArtists}
          onCheckedChange={(checked) => updateOption('includeArtists', checked)}
        />
      </Flex>
    </Flex>
  );
}
