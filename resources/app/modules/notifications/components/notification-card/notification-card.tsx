import { Notification } from '@/app/modules/notifications/models';
import { Box, Button, Card, Flex, Text } from '@radix-ui/themes';
import { Iconify } from '@/app/ui/icons/iconify.tsx';
import { useAppDispatch } from '@/app/store/hooks.ts';
import { removeNotification } from '@/app/store/notifications/notifications-slice.ts';
import { DateTime } from '@/app/ui/dates/date-time.tsx';

export interface NotificationCardProps extends React.ComponentPropsWithoutRef<'div'> {
  notification: Notification;
}

function getNotificationColor(notification: Notification) {
  switch (notification.type) {
    case 'success':
      return '#008000';
    case 'warning':
      return '#FFA500';
    case 'error':
      return '#FF0000';
    default:
      return '';
  }
}

export function NotificationCard({ notification, ...rest }: NotificationCardProps) {
  const dispatch = useAppDispatch();
  const color = getNotificationColor(notification);

  const handleDismiss = () => {
    dispatch(removeNotification({ id: notification.id }));
  };

  return (
    <Card
      {...rest}
      style={{
        backgroundColor: color,
        marginTop: '4px'
      }}
    >
      <Flex
        gap="2"
        justify="between"
        align="center"
      >
        <Box>
          <Text as="div" size="2" weight="bold">{notification.message}</Text>
          <Text as="div" size="1" color="gray">
            <DateTime date={notification.createdAt}/>
          </Text>
        </Box>

        <Button
          variant="ghost"
          onClick={() => handleDismiss()}
        >
          <Iconify icon="ion:close"/>
        </Button>
      </Flex>
    </Card>
  );
}