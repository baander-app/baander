import { Card, Flex, Heading, Text, Button, Callout } from '@radix-ui/themes';
import { Iconify } from '@/app/ui/icons/iconify.tsx';

export interface SyncResultsProps {
  jobsCompleted: number;
  onReset: () => void;
}

export function SyncResults({ jobsCompleted, onReset }: SyncResultsProps) {
  return (
    <Card>
      <Flex direction="column" gap="4">
        <Heading size="4">Sync Complete!</Heading>

        <Callout.Root color="green">
          <Callout.Icon>
            <Iconify icon="eva:checkmark-circle-2-outline" />
          </Callout.Icon>
          <Callout.Text>
            Successfully completed {jobsCompleted} sync job{jobsCompleted !== 1 ? 's' : ''}
          </Callout.Text>
        </Callout.Root>

        <Flex direction="column" gap="2">
          <Text weight="medium">What's Next?</Text>
          <Text size="2" color="gray">
            Your metadata sync has been completed. You can now browse your library with
            the updated metadata.
          </Text>
        </Flex>

        <Flex gap="3" mt="2">
          <Button onClick={onReset} size="3">
            Start Another Sync
          </Button>
        </Flex>
      </Flex>
    </Card>
  );
}
