import { Iconify, IconifyProps } from '@/ui/icons/iconify.tsx';

export interface PlayerIconProps extends Omit<IconifyProps, 'icon'> {

}

export function PlayerPlayIcon({...props}: PlayerIconProps) {
  return <Iconify icon="entypo:controller-play" {...props} />
}

export function PlayerPauseIcon({...props}: PlayerIconProps) {
  return <Iconify icon="entypo:controller-paus" {...props} />
}

export function PlayerNextIcon({...props}: PlayerIconProps) {
  return <Iconify icon="entypo:controller-next" {...props} />
}

export function PlayerPreviousIcon({...props}: PlayerIconProps) {
  return <Iconify icon="entypo:controller-jump-to-start" {...props} />
}

export function PlayerKaraokeIcon({...props}: PlayerIconProps) {
  return <Iconify icon="maki:karaoke" {...props} />
}

export function PlayerWaveFormIcon({...props}: PlayerIconProps) {
  return <Iconify icon="ph:waveform" {...props} />
}

export function PlayerWaveFormSlashIcon({...props}: PlayerIconProps) {
  return <Iconify icon="ph:waveform-slash" {...props} />
}

export function PlayerVolumeMutedIcon({...props}: PlayerIconProps) {
  return <Iconify icon="raphael:volume0" {...props} />
}

export function PlayerVolumeMediumIcon({...props}: PlayerIconProps) {
  return <Iconify icon="raphael:volume1" {...props} />
}

export function PlayerVolumeLevelHighIcon({...props}: PlayerIconProps) {
  return <Iconify icon="raphael:volume2" {...props} />
}

export function PlayerVolumeLevelFullIcon({...props}: PlayerIconProps) {
  return <Iconify icon="raphael:volume3" {...props} />
}