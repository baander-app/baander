import styled from 'styled-components'
import { Navigate } from 'react-router-dom'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { SpansSection } from '../components/diagnostics/SpansSection'

const Workspace = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
`

export function SpansPage() {
  const { isSuperAdmin } = useAdminCheck()

  if (!isSuperAdmin) return <Navigate to="/admin" replace />

  return <Workspace><SpansSection /></Workspace>
}
