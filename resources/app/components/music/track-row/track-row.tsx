import { SongResource } from '@/api-client/requests';
import { Table, TableTrProps } from '@mantine/core';

export interface TrackRowProps extends TableTrProps {
  song: SongResource;
}
export function TrackRow({song, ...rest}: TrackRowProps) {
  return (
    <Table.Tr {...rest}>
      <Table.Td>{song.track}</Table.Td>
      <Table.Td>{song.title}</Table.Td>
      <Table.Td>{song.durationHuman}</Table.Td>
    </Table.Tr>
  )
}