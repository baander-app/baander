import { LinksGroup, LinksGroupProps } from '@/ui/nav-bar-links-group/nav-bar-links-group.tsx';
import { UserButton } from '@/ui/user-button/user-button.tsx';
import { logoutUser } from '@/store/users/auth-slice.ts';
import { useAppDispatch } from '@/store/hooks.ts';
import { ActionIcon } from '@mantine/core';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { Link } from 'react-router-dom';
import styles from './user-menu.module.scss';

const menu: LinksGroupProps[] = [
  {
    label: 'Authentication',
    iconName: 'ion:key',
    links: [
      { label: 'Dev', to: 'account/dev-panel' },
      { label: 'Passkeys', to: 'account/passkeys' },
      { label: 'Password', to: '' },
      { label: 'Sessions', to: 'account/sessions' },
    ],
  },
];

export function UserMenu() {
  const dispatch = useAppDispatch();
  const links = menu.map((item) => <div key={item.label} className={styles.space}><LinksGroup {...item}/></div>);

  return (
    <div className={styles.container}>
      <div className={styles.linksInner}>{links}</div>


      <div className={styles.user}>
        <UserButton/>

        <ActionIcon variant="subtle" className={styles.settings}>
          <Link to="/user/settings">
            <Iconify icon="ion:cog" />
          </Link>
        </ActionIcon>
      </div>

      <a className={styles.link} href="#" onClick={() => dispatch(logoutUser())}>
        <span>Logout</span>
      </a>

    </div>
  );
}