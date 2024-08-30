import React from 'react';
import { Icon } from '@iconify/react';

import styles from './player-controls.module.scss';

interface BaseControlProps extends React.HTMLAttributes<HTMLButtonElement> {
  iconName: string;
  iconSize?: number;
}

function BaseControl({iconName, iconSize = 32, ...props}: BaseControlProps) {
  return (
    <button className={styles.playerControl} {...props}>
      <Icon icon={iconName} fontSize={iconSize} className={styles.icon} />
    </button>
  );
}

export interface PlayerControlProps {
  onClick: () => void;
  iconSize?: number;
}

export function NextButton({ onClick }: PlayerControlProps) {
  return <BaseControl iconName="entypo:controller-next" onClick={onClick} />;
}

export function PreviousButton({ onClick }: PlayerControlProps) {
  return <BaseControl iconName="entypo:controller-jump-to-start" onClick={onClick} />;
}

export function PlayButton({ onClick }: PlayerControlProps) {
  return <BaseControl iconName="entypo:controller-play" iconSize={32} onClick={onClick} />;
}

export function PauseButton({ onClick }: PlayerControlProps) {
  return <BaseControl iconName="entypo:controller-paus" onClick={onClick} />;
}
