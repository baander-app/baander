import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { MOOD_OPTIONS, ACCENT_OPTIONS } from '@/shared/theme/theme.types'
import { Card, CardContent } from '@/shared/components/ui/card'
import { useThemeMood } from '../hooks/use-theme-mood'
import { useAccentColor } from '../hooks/use-accent-color'

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
  grid-template-columns: repeat(auto-fit, minmax(6rem, 1fr));
  gap: 0.5rem;
`

const MoodButton = styled(Button).attrs({ variant: 'ghost' })<{ $active: boolean }>`
  height: auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.375rem;
  border-radius: var(--radius-md);
  border: 1px solid ${props => props.$active ? 'var(--color-ring)' : 'var(--color-input-boundary)'};
  padding: 0.5rem;
  background-color: ${props => props.$active ? 'var(--color-muted)' : 'transparent'};
  color: var(--color-foreground);
  min-width: 0;

  &:hover {
    border-color: ${props => props.$active ? 'var(--color-ring)' : 'var(--color-accent-text)'};
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

const AccentSwatch = styled(Button).attrs({ variant: 'ghost', size: 'icon' })<{ $active: boolean }>`
  padding: 0;
  height: 2rem;
  width: 2rem;
  border-radius: 50%;
  border: 2px solid ${props => props.$active ? 'var(--color-foreground)' : 'transparent'};
  flex-shrink: 0;
`

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
            {ACCENT_OPTIONS.map((accent) => (
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
