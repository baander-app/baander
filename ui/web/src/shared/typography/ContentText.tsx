import { useMemo, type ElementType, type HTMLAttributes } from 'react';
import styled from 'styled-components';
import { useInterfaceLanguage } from '@/shared/i18n';
import { textRole, type TextRole } from '@/shared/theme';
import { resolveContentLanguage } from './content-language';
import { scriptProfile, scriptTokens } from './script-profile';

export interface ContentTextProps extends Omit<HTMLAttributes<HTMLElement>, 'children' | 'lang'> {
  /** The type role the text is set in. */
  readonly textRole: TextRole;
  /** One content item's text, such as a song title or an artist name. */
  readonly text: string;
  /** The item's stored language, such as its album's release language, if any. */
  readonly language?: string | null;
  /** The element to render; a span by default. */
  readonly as?: ElementType;
}

const Text = styled.span<{ $role: TextRole }>`
  ${({ $role }) => textRole($role)}
`;

/**
 * Renders one content item's text with its own `lang` and the script tokens its
 * characters call for. Always writing `lang` keeps an item from inheriting another
 * item's language; `data-script` appears only when a script rule applies.
 */
export function ContentText({ textRole: role, text, language, as, ...attributes }: ContentTextProps) {
  const interfaceLanguage = useInterfaceLanguage();
  const profile = useMemo(() => scriptProfile(text), [text]);
  const lang = useMemo(
    () => resolveContentLanguage(text, language, interfaceLanguage, profile),
    [text, language, interfaceLanguage, profile],
  );
  const tokens = useMemo(() => scriptTokens(profile).join(' '), [profile]);

  return (
    <Text {...attributes} as={as} $role={role} lang={lang} data-script={tokens || undefined}>
      {text}
    </Text>
  );
}
