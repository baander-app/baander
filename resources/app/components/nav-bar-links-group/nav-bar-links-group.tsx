import { useState } from 'react';
import { Group, Box, Collapse, ThemeIcon, Text, UnstyledButton, rem } from '@mantine/core';
import { useNavigate } from 'react-router-dom';
import { Icon } from '@iconify/react';
import styles from './nav-bar-links-group.module.scss';

export interface NavbarLinksGroupLink {
  label: string;
  to?: string;
  href?: string;
}

export interface LinksGroupProps {
  iconName: string;
  label: string;
  initiallyOpened?: boolean;
  links?: NavbarLinksGroupLink[];
}

export function LinksGroup({ iconName, label, initiallyOpened, links }: LinksGroupProps) {
  const hasLinks = Array.isArray(links);
  const [opened, setOpened] = useState(initiallyOpened || false);
  const navigate = useNavigate();

  const openLink = (link: NavbarLinksGroupLink) => {
    if (link.to) {
      navigate(link.to);
    } else if(link.href) {
      window.open(link.href, '_blank');
    }
  }


  const items = (hasLinks ? links : []).map((link) => (
    <Text<'a'>
      component="a"
      className={styles.link}
      key={link.label}
      pl="sm"
      onClick={(event) => {
        event.preventDefault();

        openLink(link);
      }}
    >
      {link.label}
    </Text>
  ));

  return (
    <>
      <UnstyledButton onClick={() => setOpened((o) => !o)} className={styles.control}>
        <Group justify="space-between" gap={0}>
          <Box style={{ display: 'flex', alignItems: 'center' }}>
            <ThemeIcon variant="light" size={30}>
              <Icon icon={iconName} style={{ width: rem(18), height: rem(18) }} />
            </ThemeIcon>
            <Box ml="md">{label}</Box>
          </Box>
          {hasLinks && (
            <Icon
              className={styles.icon}
              stroke="1.5"
              style={{
                width: rem(16),
                height: rem(16),
                transform: opened ? 'rotate(-90deg)' : 'none',
              }}
              icon="material-symbols:chevron-right"
            />
          )}
        </Group>
      </UnstyledButton>
      {hasLinks ? <Collapse in={opened}>{items}</Collapse> : null}
    </>
  );
}

export interface NavbarLinksGroupProps {
  label: string;
  iconName: string;
  links: NavbarLinksGroupLink[];
  initiallyOpened?: boolean;
}

export function NavbarLinksGroup(props: NavbarLinksGroupProps) {
  return (
    <>
      {props && (
        <LinksGroup {...props} />
      )}
    </>
  );
}