import { type LufsTarget, useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store';
import styled from 'styled-components';
import { Button } from '@/shared/components/ui/button';
import { Card, CardContent } from '@/shared/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/components/ui/select';
import { Link } from 'react-router-dom';
import { PasskeyManagement } from '../components/PasskeyManagement';
import { AccountManagement } from '../components/AccountManagement';
import { AppearanceSection } from '../components/AppearanceSection';
import { DeviceManagement } from '@/features/session/components/DeviceManagement';
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check';

const VALID_LUFS_TARGETS = [-14, -16, -18, -23] as const;

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const PageHeader = styled.div`
  padding: 1rem 1.5rem;
`

const PageTitle = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.01em;
`

const ScrollArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const ContentColumn = styled.div`
  max-width: 42rem;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
`

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

const CardRow = styled(CardContent)`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const CardStack = styled(CardContent)`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const FieldHeading = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const FieldDescription = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ToggleTrack = styled.button<{ $enabled: boolean }>`
  position: relative;
  height: 1.5rem;
  width: 2.75rem;
  border-radius: 9999px;
  border: none;
  cursor: pointer;
  transition: background-color 0.2s;
  background-color: ${props => props.$enabled ? 'var(--color-white)' : 'var(--color-muted)'};
`

const ToggleThumb = styled.span<{ $enabled: boolean }>`
  position: absolute;
  top: 2px;
  left: 2px;
  height: 1.25rem;
  width: 1.25rem;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  transition: transform 0.2s;
  transform: ${props => props.$enabled ? 'translateX(1.25rem)' : 'translateX(0)'};
  background-color: ${props => props.$enabled ? 'var(--color-primary-foreground)' : 'var(--color-muted-foreground)'};
`

const FieldLabel = styled.label`
  font-size: 0.875rem;
  font-weight: 500;
`

const CardStackSmall = styled(CardContent)`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

export function SettingsPage() {
  const normalizationEnabled = useEqProcessingStore((s) => s.normalizationEnabled);
  const targetLufs = useEqProcessingStore((s) => s.targetLufs);
  const setNormalizationEnabled = useEqProcessingStore((s) => s.setNormalizationEnabled);
  const setTargetLufs = useEqProcessingStore((s) => s.setTargetLufs);
  const { isAdmin } = useAdminCheck();

  const handleLufsChange = (value: string) => {
    const parsed = parseFloat(value);
    if ((VALID_LUFS_TARGETS as readonly number[]).includes(parsed)) {
      setTargetLufs(parsed as LufsTarget);
    }
  };

  return (
    <PageContainer>
      <PageHeader>
        <PageTitle>Settings</PageTitle>
      </PageHeader>

      <ScrollArea>
        <ContentColumn>
          {/* Audio Section */}
          <Section>
            <SectionTitle>
              Audio
            </SectionTitle>

            <Card size="sm">
              <CardRow>
                <div>
                  <FieldHeading>Volume Normalization</FieldHeading>
                  <FieldDescription>
                    Automatically adjust volume to maintain consistent loudness
                  </FieldDescription>
                </div>
                <ToggleTrack
                  $enabled={normalizationEnabled}
                  onClick={() => setNormalizationEnabled(!normalizationEnabled)}
                  role="switch"
                  aria-checked={normalizationEnabled}
                  aria-label="Volume normalization"
                >
                  <ToggleThumb $enabled={normalizationEnabled} />
                </ToggleTrack>
              </CardRow>
            </Card>

            <Card size="sm">
              <CardStack>
                <FieldLabel>LUFS Target</FieldLabel>
                <FieldDescription>
                  Target loudness for volume normalization
                </FieldDescription>
                <Select
                  value={String(targetLufs)}
                  onValueChange={handleLufsChange}
                  disabled={!normalizationEnabled}
                >
                  <SelectTrigger style={{ width: '100%', maxWidth: '20rem' }}>
                    <SelectValue/>
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="-14">-14 LUFS (Spotify)</SelectItem>
                    <SelectItem value="-16">-16 LUFS (Apple Music)</SelectItem>
                    <SelectItem value="-18">-18 LUFS (YouTube)</SelectItem>
                    <SelectItem value="-23">-23 LUFS (Broadcast)</SelectItem>
                  </SelectContent>
                </Select>
              </CardStack>
            </Card>

            <Card size="sm">
              <CardRow>
                <div>
                  <FieldHeading>Equalizer</FieldHeading>
                  <FieldDescription>
                    Configure EQ bands, presets, and visualizer
                  </FieldDescription>
                </div>
                <Link to="/equalizer">
                  <Button size="sm" variant="outline">Open EQ</Button>
                </Link>
              </CardRow>
            </Card>
          </Section>

          {/* Appearance Section */}
          <AppearanceSection />

          {/* Account Section */}
          <Section>
            <SectionTitle>
              Account
            </SectionTitle>
            <AccountManagement/>
          </Section>

          {/* Security Section */}
          <Section>
            <SectionTitle>
              Security
            </SectionTitle>
            <PasskeyManagement/>
          </Section>

          {/* Devices Section */}
          <Section>
            <SectionTitle>
              Devices
            </SectionTitle>
            <DeviceManagement />
          </Section>

          {/* About Section */}
          <Section>
            <SectionTitle>
              About
            </SectionTitle>
            <Card size="sm">
              <CardStackSmall>
                <FieldHeading>Bånder</FieldHeading>
                <FieldDescription>
                  Self-hosted media library server
                </FieldDescription>
                <FieldDescription>v0.1.0</FieldDescription>
              </CardStackSmall>
            </Card>
          </Section>

          {/* Admin — only visible to ROLE_SUPER_ADMIN */}
          {isAdmin && (
            <Section>
              <Card size="sm">
                <CardRow>
                  <div>
                    <FieldHeading>Administration</FieldHeading>
                    <FieldDescription>
                      System status, jobs, configuration
                    </FieldDescription>
                  </div>
                  <Link to="/admin">
                    <Button size="sm" variant="outline">
                      Admin
                    </Button>
                  </Link>
                </CardRow>
              </Card>
            </Section>
          )}
        </ContentColumn>
      </ScrollArea>
    </PageContainer>
  );
}
