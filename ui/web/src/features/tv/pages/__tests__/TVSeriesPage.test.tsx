import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { TVSeriesPage } from '../TVSeriesPage'

const { querySeries } = vi.hoisted(() => ({ querySeries: vi.fn() }))

vi.mock('@/shared/api-client/gen/endpoints', () => ({ useTvSeriesIndex: querySeries }))

describe('TV library grid', () => {
  it('keeps the scope when paging the available series', async () => {
    querySeries.mockImplementation(({ page }: { page: number }) => ({
      data: {
        data: [{
          publicId: 'show',
          name: 'Unmatched show',
          posterUrl: null,
        }],
        meta: { current_page: page, last_page: 2, total: 51 },
      },
    }))
    const user = userEvent.setup()
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter initialEntries={['/tv/shows?library=scope']}>
          <TVSeriesPage />
        </MemoryRouter>
      </QueryClientProvider>
    )

    expect(screen.getByRole('link', { name: 'Unmatched show' })).toHaveAttribute('href', '/tv/series/show')
    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(querySeries).toHaveBeenLastCalledWith(
      { library: 'scope', page: 2, limit: 50, q: '' },
      expect.any(Object),
    )
  })
})
