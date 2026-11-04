import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BrowserColumn, type BrowserItem } from '../BrowserColumn'

const estimates: number[] = []

vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: ({ count, estimateSize }: { count: number; estimateSize: (index: number) => number }) => {
    estimates.push(estimateSize(0))
    return {
      getTotalSize: () => count * estimateSize(0),
      getVirtualItems: () =>
        Array.from({ length: count }, (_, index) => ({ index, key: index, size: estimateSize(index), start: index * estimateSize(index) })),
      scrollToIndex: () => {},
    }
  },
}))

vi.mock('@/shared/hooks/use-image-blob', () => ({ useImageBlob: () => ({ src: null }) }))

const items: BrowserItem[] = [
  { id: 'album-1', label: '直', language: 'jpn', sublabels: ['坂本龍一', '周杰倫'] },
  { id: 'album-2', label: 'ဆက်ကြည့်ရန်' },
]

describe('BrowserColumn', () => {
  beforeEach(() => {
    estimates.length = 0
  })

  it('sizes every row at 32px, including the virtualiser estimate', () => {
    render(<BrowserColumn title="Album" items={items} selectedId={null} onSelect={() => {}} />)

    expect(estimates).toContain(32)
    const options = screen.getAllByRole('option')
    expect(options[0]).toHaveTextContent('All')
    expect(options[0]).toHaveStyle({ height: '32px' })
    expect(options[1]).toHaveStyle({ height: '32px' })
  })

  it('renders the label in its stored language and each sublabel as its own element', () => {
    render(<BrowserColumn title="Album" items={items} selectedId={null} onSelect={() => {}} />)

    expect(screen.getByText('直')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('坂本龍一')).toHaveAttribute('lang', 'en')
    expect(screen.getByText('周杰倫')).toHaveAttribute('lang', 'en')
    expect(screen.getByText('ဆက်ကြည့်ရန်')).toHaveAttribute('data-script', 'no-tracking burmese burmese-only')
  })
})
