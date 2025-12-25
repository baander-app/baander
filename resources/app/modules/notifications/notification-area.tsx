import { Button, Flex, Popover, ScrollArea, Text } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/app/store/hooks.ts';
import { NotificationCard } from '@/app/modules/notifications/components/notification-card/notification-card.tsx';
import { Notification } from '@/app/modules/notifications/models';
import { Iconify } from '@/app/ui/icons/iconify.tsx';
import { clearNotifications } from '@/app/store/notifications/notifications-slice.ts';

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