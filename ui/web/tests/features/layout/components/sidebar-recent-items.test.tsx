import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SidebarRecentItems, type RecentItem } from '@/features/layout/components/SidebarRecentItems'

vi.mock('@/shared/hooks/use-image-blob', () => ({
  useImageBlob: () => ({ src: 'blob:http://localhost/mock', isLoading: false }),
}))

describe('SidebarRecentItems', () => {
  it('renders empty state when no recent items', () => {
    render(<SidebarRecentItems items={[]} />)
    expect(screen.getByText(/nothing played yet/i)).toBeVisible()
  })

  it('renders recent items with thumbnails', () => {
    const items: RecentItem[] = [
      { id: '1', title: 'OK Computer', subtitle: 'Radiohead', timestamp: '2h ago', thumbnailUrl: '/cover/1.jpg' },
      { id: '2', title: 'Blade Runner 2049', subtitle: 'Denis Villeneuve', timestamp: 'yesterday', thumbnailUrl: '/cover/2.jpg' },
    ]
    render(<SidebarRecentItems items={items} />)
    expect(screen.getByText('OK Computer')).toBeVisible()
    expect(screen.getByText('Radiohead')).toBeVisible()
    expect(screen.getByText('2h ago')).toBeVisible()
    expect(screen.getByText('Blade Runner 2049')).toBeVisible()
  })

  it('each recent item has accessible label', () => {
    const items: RecentItem[] = [
      { id: '1', title: 'OK Computer', subtitle: 'Radiohead', timestamp: '2h ago', thumbnailUrl: '/cover/1.jpg' },
    ]
    render(<SidebarRecentItems items={items} />)
    expect(screen.getByLabelText('OK Computer by Radiohead, played 2h ago')).toBeVisible()
  })

  it('renders thumbnails with lazy loading', () => {
    const items: RecentItem[] = [
      { id: '1', title: 'OK Computer', subtitle: 'Radiohead', timestamp: '2h ago', thumbnailUrl: '/cover/1.jpg' },
    ]
    const { container } = render(<SidebarRecentItems items={items} />)
    const img = container.querySelector('img')
    expect(img).toBeTruthy()
    expect(img?.getAttribute('loading')).toBe('lazy')
  })

  it('renders section header', () => {
    render(<SidebarRecentItems items={[]} />)
    expect(screen.getByText('Recent')).toBeVisible()
  })
})
