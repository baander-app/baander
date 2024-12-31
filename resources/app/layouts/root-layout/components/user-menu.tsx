import { UserButton } from '@/ui/user-button/user-button.tsx';
import { ActionIcon } from '@mantine/core';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { Link } from 'react-router-dom';
import styles from './user-menu.module.scss';

export function UserMenu() {
  return (
    <div className={styles.container}>
      <div className={styles.user}>
        <UserButton/>

        <ActionIcon variant="subtle" className={styles.settings}>
          <Link to="/user/settings">
            <Iconify icon="ion:cog" />
          </Link>
        </ActionIcon>
      </div>
    </div>
  );
}