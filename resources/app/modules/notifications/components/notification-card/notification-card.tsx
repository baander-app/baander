import { Notification } from '@/modules/notifications/models';
import { Box, Button, Card, Flex, Text } from '@radix-ui/themes';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { useAppDispatch } from '@/store/hooks.ts';
import { removeNotification } from '@/store/notifications/notifications-slice.ts';

export interface NotificationCardProps extends React.ComponentPropsWithoutRef<'div'> {
  notification: Notification;
}

export function NotificationCard({ notification, ...rest }: NotificationCardProps) {
  const dispatch = useAppDispatch();

  const handleDismiss = () => {
    dispatch(removeNotification({ id: notification.id }));
  };

  return (
    <Card {...rest}>
      <Flex gap="2" justify="between" align="center">
        <Box>
          <Text as="div" size="2" weight="bold">{notification.message}</Text>
          <Text as="div" size="1" color="gray">{notification.createdAt.toString()}</Text>
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