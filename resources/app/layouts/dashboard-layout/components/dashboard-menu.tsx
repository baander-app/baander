import { Box, Flex, ScrollArea, Text } from '@radix-ui/themes';
import { NavLink } from '@/ui/nav-link.tsx';
import { lazyImport } from '@/utils/lazy-import.ts';
import { Iconify } from '@/ui/icons/iconify.tsx';
import styles from './dashboard-menu.module.scss';

const {BaanderLogo} = lazyImport(() => import('@/ui/branding/baander-logo/baander-logo.tsx'), 'BaanderLogo');

interface MenuLink {
  label: string;
  to?: string;
  href?: string;
}

interface MenuSection {
  label: string;
  iconName: string;
  links: MenuLink[];
}

type Menu = MenuSection[];

const menu: Menu = [
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
  return (
    <Box className={styles.sidebar}>
      <Flex align="center" justify="center" py="4">
        <BaanderLogo />
      </Flex>

      <ScrollArea>
        <Box className={styles.menuContainer}>
          <NavLink to="/" className={styles.homeLink}>
            <Flex align="center" gap="2">
              <Iconify icon="heroicons:home" />
              <Text>Home</Text>
            </Flex>
          </NavLink>

          {menu.map((section: MenuSection, index: number) => (
            <Box key={index} className={styles.menuSection}>
              <Text className={styles.sectionTitle}>
                <Flex align="center" gap="2">
                  <Iconify icon={section.iconName} />
                  {section.label}
                </Flex>
              </Text>
              <Box className={styles.linksList}>
                {section.links.map((link: MenuLink, linkIndex: number) => (
                  link.to ? (
                    <NavLink 
                      key={linkIndex} 
                      to={link.to} 
                      className={styles.menuLink}
                      activeClassName={styles.activeLink}
                    >
                      {link.label}
                    </NavLink>
                  ) : link.href ? (
                    <a 
                      key={linkIndex} 
                      href={link.href} 
                      className={styles.menuLink}
                    >
                      {link.label}
                    </a>
                  ) : null
                ))}
              </Box>
            </Box>
          ))}
        </Box>
      </ScrollArea>
    </Box>
  );
}
