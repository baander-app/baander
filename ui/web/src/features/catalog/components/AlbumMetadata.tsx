import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'
import type { AlbumSummary } from '../types/album'

const Container = styled.div`
  flex-shrink: 0;
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1.5rem;
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  column-gap: 2rem;
  row-gap: 0.375rem;
`

const Field = styled.div`
  display: flex;
  flex-direction: column;
`

const Label = styled.span`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Value = styled(ContentText)`
  color: var(--color-foreground);
`

interface AlbumMetadataProps {
  album: AlbumSummary
}

interface MetadataField {
  label: string
  value: string | number | null | undefined
}

export function AlbumMetadata({ album }: AlbumMetadataProps) {
  const fields: MetadataField[] = [
    { label: 'Type', value: album.type },
    { label: 'Year', value: album.year },
    { label: 'Label', value: album.label },
    { label: 'Catalog #', value: album.catalogNumber },
    { label: 'Country', value: album.country },
    { label: 'Barcode', value: album.barcode },
    { label: 'Language', value: album.language },
    { label: 'Disambiguation', value: album.disambiguation },
    { label: 'MusicBrainz release group', value: album.mbid },
    { label: 'MusicBrainz release', value: album.releaseMbid },
  ].filter((f) => f.value != null && f.value !== '')

  if (fields.length === 0) return null

  return (
    <Container>
      <Grid>
        {fields.map((field) => (
          <Field key={field.label}>
            <Label>{field.label}</Label>
            <Value textRole="mono-sm" text={String(field.value)} />
          </Field>
        ))}
      </Grid>
    </Container>
  )
}
