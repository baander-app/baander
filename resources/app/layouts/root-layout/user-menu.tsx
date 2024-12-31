import { LinksGroup, LinksGroupProps } from '@/components/nav-bar-links-group/nav-bar-links-group.tsx';
import styles from './user-menu.module.scss';
import { UserButton } from '@/components/user-button/user-button.tsx';
import { logoutUser } from '@/store/users/auth-slice.ts';
import { useAppDispatch } from '@/store/hooks.ts';

const menu: LinksGroupProps[] = [
  {
    label: 'Authentication',
    iconName: 'ion:key',
    links: [
      { label: 'Dev', to: 'account/dev-panel' },
      { label: 'Two factor', to: 'account/two-factor' },
      { label: 'Passkey', to: '' },
      { label: 'Password', to: '' },
      { label: 'Sessions', to: 'account/sessions' },
    ],
  },
  {
    label: 'Settings',
    iconName: 'ion:cog',
    links: [
      { label: 'EQ', to: 'user/settings/equalizer' }
    ],
  }
];

export function UserMenu() {
  const dispatch = useAppDispatch();
  const links = menu.map((item) => <div key={item.label} className={styles.space}><LinksGroup {...item}/></div>);

  return (
    <div className={styles.container}>
      <div className={styles.linksInner}>{links}</div>

      <UserButton/>

      <a className={styles.link} href="#" onClick={() => dispatch(logoutUser())}>
        <span>Logout</span>
      </a>

    </div>
  );
}