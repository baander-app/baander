import { useState } from 'react';
import styled from 'styled-components';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog';
import { Trash2, Loader2 } from 'lucide-react';
import { usePasskeyRegistration } from '../hooks/use-passkey-registration';
import { usePasskeyList, useDeletePasskey } from '../hooks/use-passkey-list';

const Container = styled.div`
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const Heading = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const Subheading = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  font-size: 0.75rem;
  color: var(--color-destructive);
`

const SuccessText = styled.p`
  font-size: 0.75rem;
  color: #16a34a;
`

const SpinningIcon = styled(Loader2)`
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

const SpinningMuted = styled(SpinningIcon)`
  color: var(--color-muted-foreground);
`

const CenterSpinner = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem 0;
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 1.5rem 0;
  text-align: center;
`

const EmptyText = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const PasskeyList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const PasskeyItem = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.5rem 0.75rem;
`

const PasskeyInfo = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
`

const PasskeyText = styled.div`
  min-width: 0;
`

const PasskeyName = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const PasskeyMeta = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const SpinningWithMargin = styled(Loader2)`
  margin-right: 0.25rem;
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

export function PasskeyManagement() {
  const { register, loading: registering, error: regError, success } = usePasskeyRegistration();
  const { data: passkeys, isLoading: loadingList } = usePasskeyList();
  const deletePasskey = useDeletePasskey();
  const [deleteTarget, setDeleteTarget] = useState<{ publicId: string; name: string } | null>(null);
  const [showRegister, setShowRegister] = useState(false);
  const [passkeyName, setPasskeyName] = useState('');

  const items = passkeys ?? [];
  const isLastPasskey = items.length === 1;

  function handleRegister() {
    const name = passkeyName.trim() || 'Passkey';
    register(name);
    setShowRegister(false);
    setPasskeyName('');
  }

  function handleDeleteConfirm() {
    if (!deleteTarget) return;
    deletePasskey.mutate(deleteTarget.publicId, {
      onSuccess: () => setDeleteTarget(null),
    });
  }

  return (
    <Container>
      <HeaderRow>
        <div>
          <Heading>Passkeys</Heading>
          <Subheading>
            Passwordless authentication for your account
          </Subheading>
        </div>
        <Button size="sm" variant="outline" onClick={() => setShowRegister(true)} disabled={registering}>
          Add
        </Button>
      </HeaderRow>

      {regError && (
        <ErrorText>{regError}</ErrorText>
      )}
      {success && (
        <SuccessText>Passkey registered successfully.</SuccessText>
      )}

      {loadingList ? (
        <CenterSpinner>
          <SpinningMuted size={16} />
        </CenterSpinner>
      ) : items.length === 0 ? (
        <EmptyState>
          <EmptyText>No passkeys registered yet</EmptyText>
          <Button size="sm" onClick={() => setShowRegister(true)} disabled={registering}>
            Register your first passkey
          </Button>
        </EmptyState>
      ) : (
        <PasskeyList>
          {items.map((passkey) => (
            <PasskeyItem key={passkey.publicId}>
              <PasskeyInfo>
                <PasskeyText>
                  <PasskeyName>{passkey.name}</PasskeyName>
                  <PasskeyMeta>
                    Created {formatDate(passkey.createdAt)}
                    {passkey.lastUsedAt && ` · Last used ${formatRelative(passkey.lastUsedAt)}`}
                  </PasskeyMeta>
                </PasskeyText>
              </PasskeyInfo>
              <Button
                size="icon"
                variant="ghost"
                style={{ flexShrink: 0, height: 28, width: 28 }}
                onClick={() => setDeleteTarget(passkey)}
                disabled={deletePasskey.isPending}
              >
                <Trash2 size={14} style={{ color: 'var(--color-muted-foreground)' }} />
              </Button>
            </PasskeyItem>
          ))}
        </PasskeyList>
      )}

      {/* Register dialog */}
      <Dialog open={showRegister} onOpenChange={setShowRegister}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Register new passkey</DialogTitle>
            <DialogDescription>
              Name this passkey so you can identify it later.
            </DialogDescription>
          </DialogHeader>
          <Input
            placeholder="e.g. YubiKey, iPhone"
            value={passkeyName}
            onChange={(e) => setPasskeyName(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleRegister()}
            autoFocus
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowRegister(false)}>
              Cancel
            </Button>
            <Button onClick={handleRegister} disabled={registering}>
              {registering ? (
                <>
                  <SpinningWithMargin size={14} />
                  Registering...
                </>
              ) : (
                'Register'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete confirmation */}
      <Dialog open={!!deleteTarget} onOpenChange={(open) => !open && setDeleteTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete passkey</DialogTitle>
            <DialogDescription>
              {isLastPasskey
                ? `This is your only passkey. Deleting it means you could lose access to your account. Make sure you have another way to sign in.`
                : `Are you sure you want to delete "${deleteTarget?.name ?? ''}"?`}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              Cancel
            </Button>
            <Button
              variant={isLastPasskey ? 'destructive' : 'default'}
              onClick={handleDeleteConfirm}
              disabled={deletePasskey.isPending}
            >
              {deletePasskey.isPending ? (
                <>
                  <SpinningWithMargin size={14} />
                  Deleting...
                </>
              ) : (
                'Delete'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Container>
  );
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function formatRelative(iso: string): string {
  const now = Date.now();
  const then = new Date(iso).getTime();
  const diffMs = now - then;
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

  if (diffDays < 1) return 'today';
  if (diffDays === 1) return 'yesterday';
  if (diffDays < 30) return `${diffDays}d ago`;
  return formatDate(iso);
}
