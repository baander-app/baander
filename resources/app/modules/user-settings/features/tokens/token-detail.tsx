import { PersonalAccessTokenViewResource } from '@/api-client/requests';
import { Group, Paper, Text, Chip, Fieldset, TextInput, Code } from '@mantine/core';
import { useDateFormatter } from '@/providers/dayjs-provider.tsx';

export interface TokenDetailProps {
  item: PersonalAccessTokenViewResource;
}

export function TokenDetail({ item }: TokenDetailProps) {
  const { formatDate, fromNow } = useDateFormatter();

  return (
    <Paper>
      <Fieldset>
        <TextInput label="Token name" value={item.name ?? ''} readOnly/>

        <Text mt="sm">Abilities</Text>

        {item.abilities && (
          <Chip.Group multiple>
            {item.abilities.map((ability, index) => <Chip value={index} key={index}>{ability}</Chip>)}
          </Chip.Group>
        )}
      </Fieldset>

      <Group mt="sm">
        <Code>{item.userAgent}</Code>
      </Group>

      <Fieldset legend="Client">
        <TextInput label="Type" value={item.clientType ?? ''} readOnly/>
        <TextInput label="Name" value={item.clientName ?? ''} readOnly/>
        <TextInput label="Version" value={item.clientVersion ?? ''} readOnly/>
      </Fieldset>

      <Fieldset legend="Device">
        <TextInput label="OS" value={item.deviceOperatingSystem ?? ''} readOnly/>
        <TextInput label="Name" value={item.deviceName ?? ''} readOnly/>
      </Fieldset>

      <Fieldset legend="Time">
        <TextInput label="Created" value={formatDate(item.createdAt) ?? ''} readOnly/>
        <TextInput label="Expires" value={formatDate(item.expiresAt) ?? ''} readOnly/>
        <TextInput label="Last used" value={fromNow(item.lastUsedAt) ?? ''} readOnly/>
      </Fieldset>
    </Paper>
  );
}