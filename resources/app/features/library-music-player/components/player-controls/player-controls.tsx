import React from 'react';
import { Icon } from '@iconify/react';

import styles from './player-controls.module.scss';

interface BaseControlProps extends React.HTMLAttributes<HTMLButtonElement> {
  iconName: string;
  iconSize?: number;
}

function BaseControl({ iconName, iconSize = 32, ...props }: BaseControlProps) {
  return (
    <button className={styles.playerControl} {...props}>
      <Icon icon={iconName} fontSize={iconSize} className={styles.icon}/>
    </button>
  );
}

export interface PlayerControlProps extends Omit<BaseControlProps, 'iconName'> {
  onClick: () => void;
  iconSize?: number;
}

export function NextButton({ onClick }: PlayerControlProps) {
  return <BaseControl iconName="entypo:controller-next" onClick={onClick}/>;
}

export function PreviousButton({ onClick }: PlayerControlProps) {
  return <BaseControl iconName="entypo:controller-jump-to-start" onClick={onClick}/>;
}

export interface PlayPauseButtonProps extends PlayerControlProps {
  isPlaying: boolean;
}
export function PlayPauseButton({ onClick, isPlaying, ...props }: PlayPauseButtonProps) {
  return <BaseControl
    iconName={isPlaying ? 'entypo:controller-paus' : 'entypo:controller-play'}
    iconSize={32}
    onClick={onClick}
    {...props}
  />;
}

interface VisualizerButtonProps extends PlayerControlProps {
  isActive?: boolean;
}

export function VisualizerButton({ onClick, isActive, ...props }: VisualizerButtonProps) {
  return <BaseControl
    title={`${isActive ? 'Hide' : 'Show'} visualizer`}
    iconName={isActive ? 'ph:waveform' : 'ph:waveform-slash'}
    iconSize={26}
    onClick={onClick}
    {...props}
  />;
}

interface LyricsButtonProps extends PlayerControlProps {
  isActive?: boolean;
}

export function LyricsButton({ onClick, isActive, ...props }: LyricsButtonProps) {
  return <BaseControl
    title={`${isActive ? 'Hide' : 'Show'} lyrics`}
    iconName="maki:karaoke"
    iconSize={26}
    onClick={onClick}
    {...props}
  />;
}