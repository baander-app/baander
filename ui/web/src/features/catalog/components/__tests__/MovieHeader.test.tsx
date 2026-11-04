import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MovieHeader } from '../MovieHeader'

describe('MovieHeader', () => {
  it('detects the localised title and tagline languages from their text', () => {
    render(<MovieHeader movie={{ title: 'Spirited Away', tagline: '続きを見る' }} onPlay={() => {}} />)

    expect(screen.getByRole('heading', { name: 'Spirited Away' })).toHaveAttribute('lang', 'en')
    expect(screen.getByText('続きを見る')).toHaveAttribute('lang', 'ja')
  })
})
