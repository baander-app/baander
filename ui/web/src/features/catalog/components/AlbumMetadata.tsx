import styled from 'styled-components'

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
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Value = styled.span`
  font-family: var(--font-mono);
  font-size: 0.875rem;
  color: var(--color-foreground);
`

interface AlbumMetadataProps {
  album: Record<string, unknown>
}

interface MetadataField {
  label: string
  value: string | number | null | undefined
}

export function AlbumMetadata({ album }: AlbumMetadataProps) {
  const fields: MetadataField[] = [
    { label: 'Type', value: album.type as string | undefined },
    { label: 'Year', value: album.year as number | undefined },
    { label: 'Label', value: album.label as string | undefined },
    { label: 'Catalog #', value: album.catalogNumber as string | undefined },
    { label: 'Country', value: album.country as string | undefined },
    { label: 'Barcode', value: album.barcode as string | undefined },
    { label: 'Language', value: album.language as string | undefined },
    { label: 'Disambiguation', value: album.disambiguation as string | undefined },
  ].filter((f) => f.value != null && f.value !== '')

  const mbid = album.mbid as string | undefined
  if (mbid) {
    fields.push({ label: 'MusicBrainz ID', value: mbid })
  }

  if (fields.length === 0) return null

  return (
    <Container>
      <Grid>
        {fields.map((field) => (
          <Field key={field.label}>
            <Label>{field.label}</Label>
            <Value>{String(field.value)}</Value>
          </Field>
        ))}
      </Grid>
    </Container>
  )
}
