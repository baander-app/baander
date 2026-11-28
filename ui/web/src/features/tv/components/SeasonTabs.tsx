import type { ComponentProps, ReactNode } from 'react'
import styled from 'styled-components'
import type { TvSeasonResource } from '@/shared/api-client/gen/endpoints'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { ContentText } from '@/shared/typography'
import { textRole } from '@/shared/theme'
import { VideoActionMenu } from '@/features/catalog/components/VideoActionMenu'

const List = styled(TabsList)`
  max-width: 100%;
  justify-content: flex-start;
  overflow-x: auto;
`

const SeasonMenu = styled(VideoActionMenu)`
  flex: 0 0 auto;
`

const Count = styled.p`
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
  margin-block: var(--space-md);
`

interface SeasonTabsProps {
  seasons: TvSeasonResource[]
  unseasonedCount: number
  selected: string
  onChange: (season: string) => void
  actions: (season: TvSeasonResource) => ComponentProps<typeof VideoActionMenu>['actions']
  children: ReactNode
}

export const UNSEASONED = 'unseasoned'

function seasonName(season: TvSeasonResource) {
  return season.number === 0 ? 'Specials' : season.name || `Season ${season.number}`
}

export function SeasonTabs({
  seasons,
  unseasonedCount,
  selected,
  onChange,
  actions,
  children,
}: SeasonTabsProps) {
  const season = seasons.find(item => item.publicId === selected)
  const owned = season?.ownedCount ?? unseasonedCount
  const total = season?.totalCount ?? unseasonedCount
  const regular = seasons.filter(item => item.number !== 0)
  const specials = seasons.find(item => item.number === 0)

  function renderTab(item: TvSeasonResource) {
    const label = seasonName(item)
    return (
      <SeasonMenu key={item.publicId} label={`Options for ${label}`} actions={actions(item)}>
        <TabsTrigger value={item.publicId}>
          <ContentText text={label} textRole="body-sm" />
        </TabsTrigger>
      </SeasonMenu>
    )
  }

  return (
    <Tabs value={selected} onValueChange={onChange}>
      <List variant="line" aria-label="Seasons">
        {regular.map(renderTab)}
        {unseasonedCount > 0 ? <TabsTrigger value={UNSEASONED}>Unseasoned</TabsTrigger> : null}
        {specials ? renderTab(specials) : null}
      </List>
      <TabsContent value={selected}>
        <Count>{owned} of {total} episodes</Count>
        {children}
      </TabsContent>
    </Tabs>
  )
}
