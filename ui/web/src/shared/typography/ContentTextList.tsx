import { Fragment } from 'react';
import type { TextRole } from '@/shared/theme';
import { ContentText } from './ContentText';

export interface ContentTextListProps {
  /** The type role every name is set in. */
  readonly textRole: TextRole;
  /** The content items' texts, such as a song's artist names. */
  readonly names: readonly string[];
  /** The text between two names; a comma and a space by default. */
  readonly separator?: string;
}

/** Renders each name as its own content item, so each carries its own `lang` and script tokens. */
export function ContentTextList({ textRole, names, separator = ', ' }: ContentTextListProps) {
  return names.map((name, index) => (
    <Fragment key={`${index}-${name}`}>
      {index > 0 && separator}
      <ContentText textRole={textRole} text={name} />
    </Fragment>
  ));
}
