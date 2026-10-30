import { Switch } from '@/shared/components/ui/switch';
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store';
import styled from 'styled-components';
import { Button } from '@/shared/components/ui/button';
import { Card, CardContent } from '@/shared/components/ui/card';
import { LoudnessTargetControl } from '@/features/equalizer/components/LoudnessTargetControl';
import { Link } from 'react-router-dom';
import { PasskeyManagement } from '../components/PasskeyManagement';
import { AccountManagement } from '../components/AccountManagement';
import { AppearanceSection } from '../components/AppearanceSection';
import { EmailLanguageSetting } from '../components/EmailLanguageSetting';
import { DeviceManagement } from '@/features/session/components/DeviceManagement';
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check';

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
  gap: 1rem;
  flex-wrap: wrap;
  & > div { min-width: 0; flex: 1 1 12rem; }
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
                <Switch
                  checked={normalizationEnabled}
                  onCheckedChange={setNormalizationEnabled}
                  aria-label="Volume normalization"
                />
              </CardRow>
            </Card>

            <Card size="sm">
              <CardStack>
                <LoudnessTargetControl
                  value={targetLufs}
                  onChange={setTargetLufs}
                  disabled={!normalizationEnabled}
                />
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
            <EmailLanguageSetting/>
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
