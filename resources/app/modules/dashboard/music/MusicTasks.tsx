import { Container, Heading, Tabs } from '@radix-ui/themes';
import { SyncTab } from './components/sync-tab';
import { BrowseTab } from './components/browse-tab';

export function MusicTasks() {
  return (
    <Container mt="3">
      <Heading>Music Tasks</Heading>

      <Tabs.Root defaultValue="sync" mt="4">
        <Tabs.List>
          <Tabs.Trigger value="sync">Sync</Tabs.Trigger>
          <Tabs.Trigger value="browse">Manual Browse</Tabs.Trigger>
        </Tabs.List>

        <Tabs.Content value="sync">
          <SyncTab />
        </Tabs.Content>

        <Tabs.Content value="browse">
          <BrowseTab />
        </Tabs.Content>
      </Tabs.Root>
    </Container>
  );
}