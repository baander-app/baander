import { ScrollArea, Text, Divider, Flex } from '@mantine/core';
import { LinksGroup, LinksGroupProps } from '@/components/nav-bar-links-group/nav-bar-links-group.tsx';
import { NavLink } from '@/components/nav-link.tsx';
import { lazyImport } from '@/utils/lazy-import.ts';

const {BaanderLogo} = lazyImport(() => import('@/components/branding/baander-logo/baander-logo.tsx'), 'BaanderLogo');

import styles from './dashboard-menu.module.scss';


const menu: LinksGroupProps[] = [
  {
    label: 'Libraries',
    iconName: 'ion:library',
    links: [
      {label: 'New', to: 'libraries/new'},
      {label: 'Manage', to: 'libraries/list'},
    ],
  },
  {
    label: 'Users',
    iconName: 'heroicons:user-circle-solid',
    links: [
      {label: 'View list', to: 'users/list'},
      {label: 'Prune tokens', to: ''},
    ],
  },
  {
    label: 'System',
    iconName: 'heroicons:cog',
    links: [
      {label: 'Logs', to: 'system/log-viewer'},
      {label: 'OpCache', to: ''},
      {label: 'Queue monitor', to: 'system/queue-monitor'},
      {label: 'Php', to: 'system/php'}
    ],
  },
  {
    label: 'Documentation',
    iconName: 'heroicons:book-open-20-solid',
    links: [
      {label: 'Api', href: route('scramble.docs.ui')},
    ],
  },
];

export function DashboardMenu() {
  const links = menu.map((item) => <div key={item.label} className={styles.space}><LinksGroup {...item}/></div>);

  return (
    <nav className={styles.navbar}>
      <div className={styles.header}>
        <Flex align="center">
          <BaanderLogo/>
          <Text ml="sm" fw={500}>Dashboard</Text>
        </Flex>
      </div>

      <ScrollArea className={styles.links}>
        <NavLink to="/" label="Home"/>

        <Divider/>

        <div className={styles.linksInner}>{links}</div>
      </ScrollArea>

      <div className={styles.footer}>
      </div>
    </nav>
  );
}