import styled from 'styled-components'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/shared/components/ui/tabs'
import { useTabParam } from '@/shared/hooks/use-tab-search-params'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { ConfigurationPage } from './ConfigurationPage'
import { useState, useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useSystemSettings } from '../hooks/use-system-settings'
import { updateSystemSettings } from '../api/system-settings-api'
import { Switch } from '@/shared/components/ui/switch'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'

interface SettingDef {
  key: string
  label: string
  description: string
  type: 'toggle' | 'select'
  options?: { value: string; label: string }[]
  defaultValue: boolean | string
}

interface SettingGroup {
  title: string
  superAdminOnly: boolean
  settings: SettingDef[]
}

const SETTING_GROUPS: SettingGroup[] = [
  {
    title: 'User Management',
    superAdminOnly: true,
    settings: [
      {
        key: 'admin.can_view_users',
        label: 'View user list',
        description: 'Allow ADMIN role to view the user list',
        type: 'toggle',
        defaultValue: false,
      },
      {
        key: 'admin.can_create_users',
        label: 'Create users',
        description: 'Allow ADMIN role to create new users',
        type: 'toggle',
        defaultValue: false,
      },
    ],
  },
  {
    title: 'Content',
    superAdminOnly: false,
    settings: [
      {
        key: 'metadata.auto_sync',
        label: 'Auto-sync metadata',
        description: 'Automatically sync metadata from external sources (Discogs, MusicBrainz)',
        type: 'toggle',
        defaultValue: true,
      },
      {
        key: 'lyrics.auto_fetch',
        label: 'Auto-fetch lyrics',
        description: 'Automatically fetch lyrics for new tracks',
        type: 'toggle',
        defaultValue: true,
      },
      {
        key: 'recommendations.auto_generate',
        label: 'Auto-generate recommendations',
        description: 'Automatically generate recommendation snapshots',
        type: 'toggle',
        defaultValue: true,
      },
    ],
  },
  {
    title: 'Media',
    superAdminOnly: false,
    settings: [
      {
        key: 'transcode.enabled',
        label: 'Enable transcoding',
        description: 'Allow on-the-fly transcoding of audio tracks',
        type: 'toggle',
        defaultValue: true,
      },
      {
        key: 'transcode.max_bitrate',
        label: 'Max transcode bitrate',
        description: 'Maximum bitrate for transcoded streams',
        type: 'select',
        options: [
          { value: '128', label: '128 kbps' },
          { value: '192', label: '192 kbps' },
          { value: '256', label: '256 kbps' },
          { value: '320', label: '320 kbps' },
        ],
        defaultValue: '320',
      },
    ],
  },
  {
    title: 'Notifications',
    superAdminOnly: false,
    settings: [
      {
        key: 'notifications.push_enabled',
        label: 'Push notifications',
        description: 'Enable browser push notifications',
        type: 'toggle',
        defaultValue: false,
      },
      {
        key: 'notifications.admin_alerts',
        label: 'Admin alerts',
        description: 'Send alerts for critical system events (scan failures, health changes)',
        type: 'toggle',
        defaultValue: true,
      },
    ],
  },
]

const SETTINGS_TABS = ['general', 'config-health'] as const

const Container = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const Header = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 1rem 1.5rem;
`

const Title = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
`

const TabBar = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 0 1.5rem;
`

const StyledTabs = styled(Tabs)`
  display: flex;
  flex: 1 1 0;
  flex-direction: column;
`

const StyledTabsContent = styled(TabsContent)`
  flex: 1 1 0;
  overflow-y: auto;
`

const ContentArea = styled.div`
  padding: 1.5rem;
`

const SettingsStack = styled.div`
  margin-top: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
`

const SkeletonGroup = styled.div`
  & > div {
    margin-top: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
  }
`

const SkeletonRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 0;
`

const SkeletonLabels = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const SkeletonBar = styled.div<{ $w: string }>`
  height: 0.875rem;
  width: ${({ $w }) => $w};
  border-radius: var(--radius-md);
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const SkeletonSwitch = styled.div`
  height: 1.25rem;
  width: 2.25rem;
  border-radius: 9999px;
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
`

const SectionBorder = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 0.5rem;
  margin-bottom: 0.25rem;
`

const SectionTitle = styled.h2`
  font-size: 0.8125rem;
  font-weight: 500;
`

const SettingRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 0;
`

const SettingLabel = styled.div`
  flex: 1;
  padding-right: 1rem;
`

const SettingName = styled.div`
  font-size: 0.8125rem;
`

const SettingDesc = styled.div`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`

export function AdminSettingsPage() {
  const { isSuperAdmin } = useAdminCheck()
  const validTabs = isSuperAdmin
    ? (SETTINGS_TABS as readonly string[])
    : SETTINGS_TABS.filter((t) => t !== 'config-health')
  const [tab, setTab] = useTabParam('general', validTabs)

  return (
    <Container>
      <Header>
        <Title>Settings</Title>
      </Header>

      <StyledTabs value={tab} onValueChange={setTab}>
        <TabBar>
          <TabsList variant="line">
            <TabsTrigger value="general">General</TabsTrigger>
            {isSuperAdmin && <TabsTrigger value="config-health">Config Health</TabsTrigger>}
          </TabsList>
        </TabBar>

        <StyledTabsContent value="general">
          <AdminSettingsContent />
        </StyledTabsContent>
        {isSuperAdmin && (
          <StyledTabsContent value="config-health">
            <ConfigurationPage />
          </StyledTabsContent>
        )}
      </StyledTabs>
    </Container>
  )
}

/** Extracted settings form from the original AdminSettingsPage */
function AdminSettingsContent() {
  const { data: settings, isLoading, error } = useSystemSettings()
  const [saving, setSaving] = useState(false)
  const queryClient = useQueryClient()
  const { isSuperAdmin } = useAdminCheck()

  const handleSave = useCallback(
    async (key: string, value: boolean | string) => {
      setSaving(true)
      try {
        await updateSystemSettings({ [key]: value })
        await queryClient.invalidateQueries({ queryKey: ['system-settings'] })
      } catch {
        toast.error('Failed to save setting')
      } finally {
        setSaving(false)
      }
    },
    [queryClient],
  )

  if (isLoading) {
    return (
      <ContentArea>
        <SkeletonGroup>
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i}>
              <SkeletonBar $w="8rem" />
              <div style={{ marginTop: '0.75rem', display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                {Array.from({ length: 2 }).map((_, j) => (
                  <SkeletonRow key={j}>
                    <SkeletonLabels>
                      <SkeletonBar $w="10rem" />
                      <SkeletonBar $w="14rem" />
                    </SkeletonLabels>
                    <SkeletonSwitch />
                  </SkeletonRow>
                ))}
              </div>
            </div>
          ))}
        </SkeletonGroup>
      </ContentArea>
    )
  }

  if (error) {
    return (
      <ContentArea>
        <ErrorText>Failed to load settings.</ErrorText>
      </ContentArea>
    )
  }

  const visibleGroups = SETTING_GROUPS.filter(
    (group) => !group.superAdminOnly || isSuperAdmin,
  )

  return (
    <ContentArea>
      <SettingsStack>
        {visibleGroups.map((group) => (
          <section key={group.title}>
            <SectionBorder>
              <SectionTitle>{group.title}</SectionTitle>
            </SectionBorder>
            {group.settings.map((setting) => {
              const rawValue = settings?.[setting.key] ?? setting.defaultValue

              if (setting.type === 'toggle') {
                return (
                  <ToggleRow
                    key={setting.key}
                    setting={setting}
                    value={rawValue === true}
                    onSave={handleSave}
                    saving={saving}
                  />
                )
              }

              return (
                <SelectRow
                  key={setting.key}
                  setting={setting}
                  value={String(rawValue)}
                  onSave={handleSave}
                  saving={saving}
                />
              )
            })}
          </section>
        ))}
      </SettingsStack>
    </ContentArea>
  )
}

function ToggleRow({
  setting,
  value,
  onSave,
  saving,
}: {
  setting: SettingDef
  value: boolean
  onSave: (key: string, value: boolean) => void
  saving: boolean
}) {
  return (
    <SettingRow>
      <SettingLabel>
        <SettingName>{setting.label}</SettingName>
        <SettingDesc>{setting.description}</SettingDesc>
      </SettingLabel>
      <Switch
        checked={value}
        onCheckedChange={(checked) => onSave(setting.key, checked)}
        disabled={saving}
      />
    </SettingRow>
  )
}

function SelectRow({
  setting,
  value,
  onSave,
  saving,
}: {
  setting: SettingDef
  value: string
  onSave: (key: string, value: string) => void
  saving: boolean
}) {
  return (
    <SettingRow>
      <SettingLabel>
        <SettingName>{setting.label}</SettingName>
        <SettingDesc>{setting.description}</SettingDesc>
      </SettingLabel>
      <Select
        value={value}
        onValueChange={(v) => onSave(setting.key, v)}
        disabled={saving}
      >
        <SelectTrigger style={{ width: '140px' }}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {setting.options?.map((opt) => (
            <SelectItem key={opt.value} value={opt.value}>
              {opt.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </SettingRow>
  )
}
