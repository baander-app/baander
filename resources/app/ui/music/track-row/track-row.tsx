import React from 'react';
import { SongResource } from '@/api-client/requests';

export interface TrackRowProps extends React.HTMLAttributes<HTMLTableRowElement> {
  song: SongResource;
}
export function TrackRow({song, ...rest}: TrackRowProps) {
  return (
    <tr {...rest}>
      <td>{song.track}</td>
      <td>{song.title}</td>
      <td>{song.durationHuman}</td>
    </tr>
  )
}