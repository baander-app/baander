import { Fragment, useId } from 'react'
import styled from 'styled-components'
import { ArrowRight, Lock } from 'lucide-react'
import { Checkbox } from '@/shared/components/ui/checkbox'
import { textRole } from '@/shared/theme'
import { ContentText, ContentTextList } from '@/shared/typography'
import { isSelectable } from './match-ticks'
import type { CatalogCredit, MatchArtistAction, MatchCreditsRow, MatchField, MatchFieldRow, ProposedCredit } from './types'

/** Fields whose values are identifiers, set in mono. */
const IDENTIFIER_FIELDS: ReadonlySet<MatchField> = new Set(['mbid', 'releaseMbid', 'barcode', 'catalogNumber'])

/** Fields whose values are content names or titles, which take the item's language. */
const CONTENT_FIELDS: ReadonlySet<MatchField> = new Set(['title', 'label', 'name', 'sortName', 'disambiguation'])

/** The columns of a field or credits row: tick, label, current value, arrow, MusicBrainz value. */
const ROW_COLUMNS = '1rem 8rem minmax(0, 1fr) 0.875rem minmax(0, 1fr)'

const Row = styled.div`
  display: grid;
  grid-template-columns: ${ROW_COLUMNS};
  align-items: start;
  column-gap: 0.75rem;
  row-gap: 0.25rem;
  padding: 0.375rem 0;
  ${textRole('body-sm')}

  @media (max-width: 767px) {
    grid-template-columns: 1rem minmax(0, 1fr) 0.875rem minmax(0, 1fr);

    & > [data-row-label] {
      grid-column: 2 / -1;
    }

    & > [data-row-current] {
      grid-column: 2;
    }
  }
`

const Heads = styled.div`
  display: grid;
  grid-template-columns: ${ROW_COLUMNS};
  column-gap: 0.75rem;
  ${textRole('label')}
  color: var(--color-muted-foreground);

  @media (max-width: 767px) {
    display: none;
  }
`

const ActionRow = styled.div`
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: start;
  column-gap: 0.75rem;
  padding: 0.375rem 0;
  ${textRole('body-sm')}
`

const TickCell = styled.span`
  display: flex;
  min-height: 1.25rem;
  align-items: center;
`

const RowLabel = styled.label`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  color: var(--color-foreground);
`

const Tag = styled.span`
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  ${textRole('caption')}
  color: var(--color-muted-foreground);

  & svg {
    width: 0.75rem;
    height: 0.75rem;
  }
`

const Value = styled.span`
  min-width: 0;
  overflow-wrap: anywhere;
  color: var(--color-foreground);
`

const Muted = styled.span`
  color: var(--color-muted-foreground);
`

const Mono = styled.span`
  ${textRole('mono-sm')}
`

const Arrow = styled(ArrowRight)`
  width: 0.875rem;
  height: 0.875rem;
  align-self: center;
  color: var(--color-muted-foreground);
`

const CreditList = styled.span`
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 0.125rem;
`

const CreditLine = styled.span`
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  column-gap: 0.375rem;
  overflow-wrap: anywhere;
`

const Caption = styled.span`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

/** The column titles above field and credits rows. */
export function MatchRowHeads() {
  return (
    <Heads aria-hidden>
      <span />
      <span />
      <span>Current</span>
      <span />
      <span>MusicBrainz</span>
    </Heads>
  )
}

interface TickProps {
  readonly ticked: boolean
  /** An apply is running: no tick may change. */
  readonly applying: boolean
  readonly onTick: (ticked: boolean) => void
}

interface FieldTickRowProps extends TickProps {
  readonly row: MatchFieldRow
  /** The field's name as the row shows it. */
  readonly label: string
  /** The name the tick announces; a song's row names its song. */
  readonly accessibleName?: string
  /** The item's stored language, which its titles and names take. */
  readonly language?: string | null
}

/** One field of a comparison: its tick, the value the item holds and the value MusicBrainz gives. */
export function FieldTickRow({ row, label, accessibleName = label, language, ticked, applying, onTick }: FieldTickRowProps) {
  const id = useId()

  return (
    <Row data-match-row>
      <TickCell>
        <Checkbox
          id={id}
          aria-label={accessibleName}
          checked={ticked}
          disabled={applying || !isSelectable(row)}
          onCheckedChange={(checked) => onTick(checked === true)}
        />
      </TickCell>
      <RowLabel htmlFor={id} data-row-label>
        {label}
        {row.locked && (
          <Tag>
            <Lock aria-hidden />
            Locked
          </Tag>
        )}
        {!row.locked && !row.changes && <Tag>Unchanged</Tag>}
      </RowLabel>
      <Value data-row-current>
        <FieldValue field={row.field} value={row.current} side="current" language={language} />
      </Value>
      <Arrow role="img" aria-label="becomes" />
      <Value>
        <FieldValue field={row.field} value={row.proposed} side="proposed" language={language} />
      </Value>
    </Row>
  )
}

interface FieldValueProps {
  readonly field: MatchField
  readonly value: MatchFieldRow['current']
  readonly side: 'current' | 'proposed'
  readonly language?: string | null
}

function FieldValue({ field, value, side, language }: FieldValueProps) {
  if (value === null || (Array.isArray(value) && value.length === 0)) {
    return <Muted>None</Muted>
  }
  if (field === 'cover' || typeof value === 'boolean') {
    return <>{side === 'current' ? 'Current cover' : 'Front cover from MusicBrainz'}</>
  }
  if (Array.isArray(value)) {
    return <ContentTextList textRole="body-sm" names={value} />
  }
  if (IDENTIFIER_FIELDS.has(field)) {
    return <Mono>{value}</Mono>
  }
  if (CONTENT_FIELDS.has(field) && typeof value === 'string') {
    return <ContentText textRole="body-sm" text={value} language={language} />
  }

  return <>{value}</>
}

interface CreditsTickRowProps extends TickProps {
  readonly row: MatchCreditsRow
  readonly label: string
  readonly accessibleName?: string
}

/** The primary and featured credits of the item or a song now, and as MusicBrainz credits them. */
export function CreditsTickRow({ row, label, accessibleName = label, ticked, applying, onTick }: CreditsTickRowProps) {
  const id = useId()

  return (
    <Row data-match-row>
      <TickCell>
        <Checkbox
          id={id}
          aria-label={accessibleName}
          checked={ticked}
          disabled={applying || !row.changes}
          onCheckedChange={(checked) => onTick(checked === true)}
        />
      </TickCell>
      <RowLabel htmlFor={id} data-row-label>
        {label}
        {!row.changes && <Tag>Unchanged</Tag>}
      </RowLabel>
      <CreditList data-row-current>
        {row.current.length === 0 ? <Muted>None</Muted> : row.current.map((credit, index) => <CurrentCredit key={index} credit={credit} />)}
      </CreditList>
      <Arrow role="img" aria-label="becomes" />
      <CreditList>
        {row.proposed.map((credit, index) => (
          <ProposedCreditLine key={index} credit={credit} />
        ))}
      </CreditList>
    </Row>
  )
}

function CurrentCredit({ credit }: { readonly credit: CatalogCredit }) {
  return (
    <CreditLine>
      <ContentText textRole="body-sm" text={credit.artistName} />
      {credit.role !== null && <Caption>{credit.role}</Caption>}
      <CreditedAs name={credit.artistName} creditedName={credit.creditedName} />
    </CreditLine>
  )
}

function ProposedCreditLine({ credit }: { readonly credit: ProposedCredit }) {
  return (
    <CreditLine>
      <ContentText textRole="body-sm" text={credit.artist.name} />
      <Caption>{credit.role}</Caption>
      <CreditedAs name={credit.artist.name} creditedName={credit.creditedName} />
      {credit.artist.publicId === null && <Caption>new artist</Caption>}
      {credit.artist.takesId && <Caption>takes the MusicBrainz ID</Caption>}
    </CreditLine>
  )
}

/** The name a credit shows, when it differs from the artist's own. */
function CreditedAs({ name, creditedName }: { readonly name: string; readonly creditedName: string }) {
  if (name === creditedName) {
    return null
  }

  return (
    <Caption>
      as “<ContentText textRole="caption" text={creditedName} />”
    </Caption>
  )
}

interface ArtistActionRowProps extends TickProps {
  readonly action: MatchArtistAction
}

/** An artist the match would merge into another or remove; unticking it keeps the artist as it is. */
export function ArtistActionRow({ action, ticked, applying, onTick }: ArtistActionRowProps) {
  const id = useId()
  const target = action.target
  const name = target === null ? `Remove “${action.artistName}”` : `Merge “${action.artistName}” into ${target.name}`

  return (
    <ActionRow data-match-row>
      <TickCell>
        <Checkbox id={id} aria-label={name} checked={ticked} disabled={applying} onCheckedChange={(checked) => onTick(checked === true)} />
      </TickCell>
      <RowLabel htmlFor={id}>
        <span>
          {target === null ? 'Remove' : 'Merge'} “<ContentText textRole="body-sm" text={action.artistName} />”
          {target !== null && (
            <Fragment>
              {' into '}
              <ContentText textRole="body-sm" text={target.name} />
              {target.publicId === null && <Caption> (new artist)</Caption>}
            </Fragment>
          )}
        </span>
        <Caption>
          {target === null
            ? 'No artist takes over its credits, so it is removed with its favorites.'
            : 'Its credits, favorites and play history move to that artist.'}
        </Caption>
      </RowLabel>
    </ActionRow>
  )
}
