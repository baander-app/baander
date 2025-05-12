import { PersonalAccessTokenViewResource } from '@/api-client/requests';
import { Code, Badge, Card, DataList } from '@radix-ui/themes';
import { useDateFormatter } from '@/providers/dayjs-provider.tsx';
import { DateTime } from '@/ui/dates/date-time.tsx';

export interface TokenDetailProps {
  item: PersonalAccessTokenViewResource;
}

export function TokenDetail({ item }: TokenDetailProps) {
  const { fromNow } = useDateFormatter();

  return (
    <Card>
      <DataList.Root>
        <DataList.Item>
          <DataList.Label>Name</DataList.Label>
          <DataList.Value>{item.name}</DataList.Value>
        </DataList.Item>

        {item.abilities && (
          <DataList.Item>
            <DataList.Label>Abilities</DataList.Label>
            <DataList.Value>
              {item.abilities && (
                <>
                  {item.abilities.map((ability, index) => <Badge key={index} variant="soft" radius="full">{ability}</Badge>)}
                </>
              )}
            </DataList.Value>
          </DataList.Item>
        )}

        <DataList.Item>
          <DataList.Label>User Agent</DataList.Label>
          <DataList.Value>
            <Code>{item.userAgent}</Code>
          </DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Client Type</DataList.Label>
          <DataList.Value>{item.clientType ?? ''}</DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Client Name</DataList.Label>
          <DataList.Value>{item.clientName ?? ''}</DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Client Version</DataList.Label>
          <DataList.Value>{item.clientVersion ?? ''}</DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Device OS</DataList.Label>
          <DataList.Value>{item.deviceOperatingSystem ?? ''}</DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Device Name</DataList.Label>
          <DataList.Value>{item.deviceName ?? ''}</DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Created</DataList.Label>
          <DataList.Value>
            <DateTime date={item.createdAt ?? ''} />
          </DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Expires</DataList.Label>
          <DataList.Value>
            <DateTime date={item.expiresAt ?? ''} />
          </DataList.Value>
        </DataList.Item>

        <DataList.Item>
          <DataList.Label>Last used</DataList.Label>
          <DataList.Value>{fromNow(item.lastUsedAt) ?? ''}</DataList.Value>
        </DataList.Item>
      </DataList.Root>
    </Card>
  );
}