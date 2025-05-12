import { Button, Flex, Popover, ScrollArea, Text } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import { NotificationCard } from '@/modules/notifications/components/notification-card/notification-card.tsx';
import { Notification } from '@/modules/notifications/models';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { clearNotifications } from '@/store/notifications/notifications-slice.ts';

export function NotificationArea() {
  const { notifications } = useAppSelector(state => state.notifications);

  return (
    <Popover.Root>
      <Popover.Trigger>
        <Button size="3" variant="ghost">
          <Iconify icon="ion:notifications"/> {notifications.length > 0 ? '(' + notifications.length + ')' : ''}
        </Button>
      </Popover.Trigger>
      <Popover.Content>
        <NotificationList notifications={notifications}/>
      </Popover.Content>
    </Popover.Root>
  );
}

interface NotificationListProps {
  notifications: Notification[];
}

function NotificationList({ notifications }: NotificationListProps) {
  const dispatch = useAppDispatch();

  const handleClear = () => {
    dispatch(clearNotifications());
  };

  return (
    <Flex direction="column" maxWidth="300px">
      <Text as="div" size="2" weight="bold" align="center">Notifications</Text>

      <ScrollArea style={{ height: 300 }}>
        <Flex direction="column">
          {notifications.map(notification => (
            <NotificationCard
              notification={notification}
              key={notification.id}
            />
          ))}
        </Flex>
      </ScrollArea>

      <Button
        mt="2"
        size="2"
        variant="ghost"
        onClick={() => handleClear()}
      >Clear</Button>
    </Flex>
  );
}