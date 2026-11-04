import { notificationCategoryColors } from '@/features/notification/category-colors'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import { useQuery } from '@tanstack/react-query'
import { notificationApi, type NotificationItem } from '@/features/notification/api/notification-api'
import { formatRelativeTime } from '@/shared/utils/format-relative-time'


const EmptyText = styled.p`
  ${textRole('body-sm')}
  color: var(--color-muted-foreground);
`

const List = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const Row = styled.div<{ $borderColor: string }>`
  border-left: 2px solid ${({ $borderColor }) => $borderColor};
  padding: 0.375rem 0 0.375rem 0.75rem;
  transition: opacity 0.15s;
`

const RowTitle = styled.div`
  ${textRole('body-sm')}
`

const RowTime = styled.div`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
  margin-top: 0.125rem;
`

export function AlertHistory() {
  const { data: alerts } = useQuery({
    queryKey: ['admin-alerts'],
    queryFn: ({ signal }) =>
      notificationApi.list({ category: 'admin_operations', limit: 10 }, signal).then((page) => page.data),
    retry: false,
  })

  if (!alerts || alerts.length === 0) {
    return <EmptyText>No recent alerts</EmptyText>
  }

  return (
    <List>
      {alerts.map((alert) => (
        <AlertRow key={alert.publicId} alert={alert} />
      ))}
    </List>
  )
}

function AlertRow({ alert }: { alert: NotificationItem }) {
  const borderColor = notificationCategoryColors[alert.category] ?? 'var(--color-muted-foreground)'

  return (
    <Row $borderColor={borderColor}>
      <RowTitle>{alert.title ?? alert.eventType}</RowTitle>
      <RowTime>{formatRelativeTime(alert.createdAt)}</RowTime>
    </Row>
  )
}
