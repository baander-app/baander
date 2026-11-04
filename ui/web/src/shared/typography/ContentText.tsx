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
  readonly element?: ElementType;
}

const Text = styled.span<{ $role: TextRole }>`
  ${({ $role }) => textRole($role)}
`;

/**
 * Renders one content item's text with its own `lang` and the script tokens its
 * characters call for. Always writing `lang` keeps an item from inheriting another
 * item's language; `data-script` appears only when a script rule applies.
 */
export function ContentText({ textRole: role, text, language, element, ...attributes }: ContentTextProps) {
  const interfaceLanguage = useInterfaceLanguage();
  const { lang, tokens } = useMemo(() => {
    const profile = scriptProfile(text);

    return {
      lang: resolveContentLanguage(text, language, interfaceLanguage, profile),
      tokens: scriptTokens(profile).join(' '),
    };
  }, [text, language, interfaceLanguage]);

  return (
    <Text {...attributes} as={element} $role={role} lang={lang} data-script={tokens || undefined}>
      {text}
    </Text>
  );
}
