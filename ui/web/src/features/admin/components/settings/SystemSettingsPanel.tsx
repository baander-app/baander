import styled from 'styled-components'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import type {
  SettingDefinitionResource,
  SystemSettingResource,
} from '@/shared/api-client/gen/endpoints'
import { useSystemSettingDefinitions, useSystemSettings } from '../../hooks/use-system-settings'
import { SystemSettingRow } from './SystemSettingRow'

const ContentArea = styled.div`
  padding: 1.5rem;
`

const Stack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2rem;
`

const SectionHeader = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 0.5rem;
  margin-bottom: 0.25rem;
`

const SectionTitle = styled.h2`
  font-size: 0.8125rem;
  font-weight: 500;
`

const Note = styled.p`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const ErrorBlock = styled.div`
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
`

const SkeletonLine = styled(Skeleton)<{ $width: string }>`
  height: 0.875rem;
  width: ${({ $width }) => $width};
  background: var(--color-muted);
`

const SkeletonRow = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.75rem 0;
`

interface SettingGroup {
  title: string
  definitions: SettingDefinitionResource[]
}

/** Groups definitions by their group label, in the order the server lists them. */
function groupDefinitions(definitions: SettingDefinitionResource[]): SettingGroup[] {
  const groups = new Map<string, SettingDefinitionResource[]>()
  for (const definition of definitions) {
    const members = groups.get(definition.group) ?? []
    members.push(definition)
    groups.set(definition.group, members)
  }

  return Array.from(groups, ([title, members]) => ({ title, definitions: members }))
}

/** Server-wide settings, rendered from the backend's setting definitions. */
export function SystemSettingsPanel() {
  const { isSuperAdmin } = useAdminCheck()
  const definitions = useSystemSettingDefinitions()
  const settings = useSystemSettings()

  if (definitions.isPending || settings.isPending) {
    return (
      <ContentArea>
        <Stack aria-busy="true" aria-label="Loading settings">
          {Array.from({ length: 3 }).map((_, group) => (
            <div key={group}>
              <SkeletonLine $width="8rem" />
              {Array.from({ length: 2 }).map((_, row) => (
                <SkeletonRow key={row}>
                  <SkeletonLine $width="10rem" />
                  <SkeletonLine $width="14rem" />
                </SkeletonRow>
              ))}
            </div>
          ))}
        </Stack>
      </ContentArea>
    )
  }

  if (definitions.isError || settings.isError) {
    const retry = async () => {
      await Promise.all([definitions.refetch(), settings.refetch()])
    }

    return (
      <ContentArea>
        <ErrorBlock>
          <ErrorText role="alert">Unable to load settings.</ErrorText>
          <Button variant="ghost" onClick={retry}>
            Retry
          </Button>
        </ErrorBlock>
      </ContentArea>
    )
  }

  const settingsByKey = new Map<string, SystemSettingResource>(
    settings.data.map((setting) => [setting.key, setting]),
  )
  const groups = groupDefinitions(definitions.data)

  return (
    <ContentArea>
      <Stack>
        {!isSuperAdmin && <Note>Only super admins can change server settings.</Note>}
        {groups.length === 0 && <Note>No server settings are defined.</Note>}
        {groups.map((group) => (
          <section key={group.title}>
            <SectionHeader>
              <SectionTitle>{group.title}</SectionTitle>
            </SectionHeader>
            {group.definitions.map((definition) => (
              <SystemSettingRow
                key={definition.key}
                definition={definition}
                setting={settingsByKey.get(definition.key)}
                canEdit={isSuperAdmin}
              />
            ))}
          </section>
        ))}
      </Stack>
    </ContentArea>
  )
}
