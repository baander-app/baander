import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import '@/shared/i18n';
import { ContentTextList } from '../ContentTextList';

describe('ContentTextList', () => {
  afterEach(() => {
    cleanup();
  });

  it('renders each name as its own element with its own lang, separated by a comma', () => {
    const { container } = render(
      <p data-testid="artists">
        <ContentTextList textRole="caption" names={['宇多田ヒカル', 'Skrillex', 'ลิซ่า']} />
      </p>,
    );

    expect(screen.getByTestId('artists')).toHaveTextContent('宇多田ヒカル, Skrillex, ลิซ่า');
    expect(container.querySelectorAll('[lang]')).toHaveLength(3);
    expect(screen.getByText('宇多田ヒカル')).toHaveAttribute('lang', 'ja');
    expect(screen.getByText('Skrillex')).toHaveAttribute('lang', 'en');
    expect(screen.getByText('ลิซ่า')).toHaveAttribute('lang', 'th');
  });

  it('places the given separator between names only', () => {
    render(
      <p data-testid="genres">
        <ContentTextList textRole="body-sm" names={['Jazz', 'Blues']} separator=" / " />
      </p>,
    );

    expect(screen.getByTestId('genres').textContent).toBe('Jazz / Blues');
  });
});
