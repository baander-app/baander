import styled from 'styled-components'
import { Card, CardContent } from '@/shared/components/ui/card'
import { useThemeMood, type ThemeMood } from '../hooks/use-theme-mood'
import { useAccentColor, type AccentColor } from '../hooks/use-accent-color'

const Section = styled.section`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const SectionTitle = styled.h2`
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--color-muted-foreground);
  text-transform: uppercase;
`

const CardContentStyled = styled(CardContent)`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const FieldHeading = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const FieldDescription = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const MoodGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.5rem;
`

const MoodButton = styled.button<{ $active: boolean }>`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.375rem;
  border-radius: var(--radius-lg);
  border: 1px solid ${props => props.$active ? 'var(--color-primary)' : 'var(--color-border)'};
  padding: 0.5rem;
  background-color: ${props => props.$active ? 'color-mix(in srgb, var(--color-primary) 10%, transparent)' : 'transparent'};
  transition: border-color 0.15s, background-color 0.15s;

  &:hover {
    border-color: ${props => props.$active ? 'var(--color-primary)' : 'color-mix(in srgb, var(--color-muted-foreground) 50%, transparent)'};
  }
`

const MoodPreviewOuter = styled.div`
  height: 2rem;
  width: 100%;
  border-radius: var(--radius-md);
  border: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  position: relative;
  overflow: hidden;
`

const MoodPreviewInner = styled.div`
  height: 100%;
  width: 50%;
  border-radius: var(--radius-md) 0 0 var(--radius-md);
`

const MoodLabel = styled.span`
  font-size: 0.75rem;
  font-weight: 500;
`

const AccentRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
`

const AccentSwatch = styled.button<{ $active: boolean }>`
  height: 2rem;
  width: 2rem;
  border-radius: 50%;
  border: 2px solid ${props => props.$active ? 'var(--color-foreground)' : 'transparent'};
  transition: transform 0.15s;
  transform: ${props => props.$active ? 'scale(1.1)' : 'scale(1)'};

  &:hover {
    transform: ${props => props.$active ? 'scale(1.1)' : 'scale(1.05)'};
  }
`

const MOOD_OPTIONS: { value: ThemeMood; label: string; preview: { bg: string; fg: string; card: string } }[] = [
  {
    value: 'dark',
    label: 'Dark',
    preview: { bg: '#000000', fg: '#f0f0f2', card: '#0a0a0b' },
  },
  {
    value: 'warm',
    label: 'Warm',
    preview: { bg: '#faf5ee', fg: '#3d3225', card: '#f5ede0' },
  },
  {
    value: 'cool',
    label: 'Cool',
    preview: { bg: '#f0f4f8', fg: '#2d3748', card: '#e8edf3' },
  },
  {
    value: 'balanced',
    label: 'Balanced',
    preview: { bg: '#f5f5f5', fg: '#2d2d2d', card: '#ebebeb' },
  },
]

const ACCENT_COLORS: { value: AccentColor; hex: string }[] = [
  { value: 'white', hex: '#e4e4e7' },
  { value: 'blue', hex: '#60a5fa' },
  { value: 'violet', hex: '#a78bfa' },
  { value: 'rose', hex: '#fb7185' },
  { value: 'amber', hex: '#fbbf24' },
  { value: 'emerald', hex: '#34d399' },
  { value: 'cyan', hex: '#22d3ee' },
  { value: 'pink', hex: '#f0abfc' },
]

export function AppearanceSection() {
  const { mood, setMood } = useThemeMood()
  const { color, setColor } = useAccentColor()

  return (
    <Section>
      <SectionTitle>
        Appearance
      </SectionTitle>

      {/* Mood Picker */}
      <Card size="sm">
        <CardContentStyled>
          <div>
            <FieldHeading>Theme</FieldHeading>
            <FieldDescription>
              Choose the color palette for the interface
            </FieldDescription>
          </div>
          <MoodGrid>
            {MOOD_OPTIONS.map((option) => (
              <MoodButton
                key={option.value}
                $active={mood === option.value}
                onClick={() => setMood(option.value)}
                aria-label={`Select ${option.label} theme`}
                aria-pressed={mood === option.value}
              >
                <MoodPreviewOuter style={{ backgroundColor: option.preview.bg }}>
                  <MoodPreviewInner style={{ backgroundColor: option.preview.card }} />
                </MoodPreviewOuter>
                <MoodLabel>{option.label}</MoodLabel>
              </MoodButton>
            ))}
          </MoodGrid>
        </CardContentStyled>
      </Card>

      {/* Accent Color Picker */}
      <Card size="sm">
        <CardContentStyled>
          <div>
            <FieldHeading>Accent Color</FieldHeading>
            <FieldDescription>
              Choose the primary accent color
            </FieldDescription>
          </div>
          <AccentRow>
            {ACCENT_COLORS.map((accent) => (
              <AccentSwatch
                key={accent.value}
                $active={color === accent.value}
                style={{ backgroundColor: accent.hex }}
                onClick={() => setColor(accent.value)}
                aria-label={`Select ${accent.value} accent color`}
                aria-pressed={color === accent.value}
              />
            ))}
          </AccentRow>
        </CardContentStyled>
      </Card>
    </Section>
  )
}
