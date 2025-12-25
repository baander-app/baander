import { Card, Flex, Heading, Progress, Text } from '@radix-ui/themes';

export interface SyncProgressProps {
  queued: number;
  running: number;
  completed: number;
}

export function SyncProgress({ queued, running, completed }: SyncProgressProps) {
  const total = queued + running + completed;
  const progress = total > 0 ? (completed / total) * 100 : 0;

  return (
    <Card mb="4">
      <Flex direction="column" gap="4">
        <Heading size="4">Sync Progress</Heading>

        <Flex gap="4" align="center">
          <Text size="2" weight="bold">
            {completed} / {total} jobs completed
          </Text>
          <Text size="2" color="gray">
            ({Math.round(progress)}%)
          </Text>
        </Flex>

        <Progress value={progress} size="3" />

        <Flex gap="6">
          <Flex direction="column" gap="1">
            <Text size="2" color="gray">
              Queued
            </Text>
            <Text size="4" weight="bold">
              {queued}
            </Text>
          </Flex>

          <Flex direction="column" gap="1">
            <Text size="2" color="gray">
              Running
            </Text>
            <Text size="4" weight="bold">
              {running}
            </Text>
          </Flex>

          <Flex direction="column" gap="1">
            <Text size="2" color="gray">
              Completed
            </Text>
            <Text size="4" weight="bold">
              {completed}
            </Text>
          </Flex>
        </Flex>

        {(queued > 0 || running > 0) && (
          <Text size="2" color="gray">
            This page will update automatically every 2 seconds...
          </Text>
        )}
      </Flex>
    </Card>
  );
}
