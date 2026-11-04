import { afterEach, describe, expect, it } from 'vitest';
import { act, cleanup, render, screen } from '@testing-library/react';
import i18n from 'i18next';
import '@/shared/i18n';
import { declaration, renderWithCss, rulesForElement } from '@/shared/theme/__tests__/css-text';
import { ContentText } from '../ContentText';

function rendered(text: string): HTMLElement {
  return screen.getByText(text);
}

describe('ContentText', () => {
  afterEach(async () => {
    cleanup();
    await i18n.changeLanguage('en');
  });

  it('removes tracking from a Thai label and keeps it on a Latin one', () => {
    render(
      <>
        <ContentText textRole="label" text="ฤดูกาล 2" />
        <ContentText textRole="label" text="Season 2" />
      </>,
    );

    expect(rendered('ฤดูกาล 2')).toHaveAttribute('data-script', 'no-tracking thai');
    expect(rendered('ฤดูกาล 2')).toHaveAttribute('lang', 'th');
    expect(rendered('Season 2')).not.toHaveAttribute('data-script');
    expect(rendered('Season 2')).toHaveAttribute('lang', 'en');
  });

  it('enlarges wholly Burmese body text and keeps a mixed string at its role size', () => {
    render(
      <>
        <ContentText textRole="body" text="ဆက်ကြည့်ရန်" />
        <ContentText textRole="body" text="Season 1 · စီးဇင်း 1" />
      </>,
    );

    expect(rendered('ဆက်ကြည့်ရန်')).toHaveAttribute('data-script', 'no-tracking burmese burmese-only');
    expect(rendered('Season 1 · စီးဇင်း 1')).toHaveAttribute('data-script', 'no-tracking burmese');
  });

  it('gives each item its own language so a song title and its artist never share one', () => {
    render(
      <p>
        <ContentText textRole="body-sm" text="直" language="jpn" />
        {' · '}
        <ContentText textRole="body-sm" text="周杰倫" />
      </p>,
    );

    expect(rendered('直')).toHaveAttribute('lang', 'ja');
    expect(rendered('直')).toHaveAttribute('data-script', 'no-tracking cjk');
    expect(rendered('周杰倫')).toHaveAttribute('lang', 'en');
  });

  it('marks a kana title Japanese even when its album is stored as English', () => {
    render(<ContentText textRole="body-sm" text="続きを見る" language="en" />);

    expect(rendered('続きを見る')).toHaveAttribute('lang', 'ja');
  });

  it('renders empty text with the interface language and no script tokens', () => {
    const { container } = render(<ContentText textRole="caption" text="" />);
    const element = container.firstElementChild;

    expect(element?.tagName).toBe('SPAN');
    expect(element).toHaveAttribute('lang', 'en');
    expect(element).not.toHaveAttribute('data-script');
  });

  it('follows a change of interface language', async () => {
    render(<ContentText textRole="body" text="Dune: Part Two" />);

    i18n.addResourceBundle('de', 'translation', { greeting: 'Hallo' });
    await act(async () => {
      await i18n.changeLanguage('de');
    });

    expect(rendered('Dune: Part Two')).toHaveAttribute('lang', 'de');
  });

  it('renders the requested element with the role applied to it', () => {
    const { container, cssText } = renderWithCss(
      <ContentText textRole="title" text="Dune: Part Two" element="h2" className="details-title" />,
    );
    const heading = container.querySelector('h2');

    expect(heading).not.toBeNull();
    expect(heading).toHaveClass('details-title');

    const rules = rulesForElement(heading!, cssText);

    expect(rules.map(rule => declaration(rule, 'font-size'))).toContain(
      'calc(var(--text-title-size) + var(--script-size-boost, 0px))',
    );
  });
});
