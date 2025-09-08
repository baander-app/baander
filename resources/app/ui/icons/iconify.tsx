import React, { ComponentType, SVGProps } from 'react';

// Import only the icons you actually use.
// Add to this list as needed.
import HeroiconsHome from '~icons/heroicons/home';
import HeroiconsUserCircleSolid from '~icons/heroicons/user-circle-solid';

import IonClose from '~icons/ion/close';
import MusicalNotes from '~icons/ion/musical-notes';
import IonNotifications from '~icons/ion/notifications';
import IonKeyOutline from '~icons/ion/key-outline';
import EntypoControllerPlay from '~icons/entypo/controller-play';
import EntypoControllerPause from '~icons/entypo/controller-paus';
import EntypoControllerNext from '~icons/entypo/controller-next';
import EntypoControllerJumpToStart from '~icons/entypo/controller-jump-to-start';
import PhWaveform from '~icons/ph/waveform';
import PhWaveformSlash from '~icons/ph/waveform-slash';
import PhEqualizer from '~icons/ph/equalizer';
import RaphaelVolume0 from '~icons/raphael/volume0';
import RaphaelVolume1 from '~icons/raphael/volume1';
import RaphaelVolume2 from '~icons/raphael/volume2';
import RaphaelVolume3 from '~icons/raphael/volume3';
import MakiKaraoke from '~icons/maki/karaoke';
import ArcticonsQuicklyric from '~icons/arcticons/quicklyric';
import AkarMusicAlbumFill from '~icons/akar-icons/music-album-fill';
import EntypoControllerPaus from '~icons/entypo/controller-paus';

import { Env } from '@/common/env.ts';

// If you use more icons, add them above and wire them here.
const ICONS: Record<string, ComponentType<SVGProps<SVGSVGElement>>> = {
  'heroicons:home': HeroiconsHome,
  'heroicons:user-circle-solid': HeroiconsUserCircleSolid,
  'ion:close': IonClose,
  'ion:notifications': IonNotifications,
  'ion:key-outline': IonKeyOutline,
  'ion:musical-notes': MusicalNotes,
  'entypo:controller-play': EntypoControllerPlay,
  'entypo:controller-pause': EntypoControllerPause,
  'entypo:controller-next': EntypoControllerNext,
  'entypo:controller-jump-to-start': EntypoControllerJumpToStart,
  'ph:waveform': PhWaveform,
  'ph:waveform-slash': PhWaveformSlash,
  'ph:equalizer': PhEqualizer,
  'raphael:volume0': RaphaelVolume0,
  'raphael:volume1': RaphaelVolume1,
  'raphael:volume2': RaphaelVolume2,
  'raphael:volume3': RaphaelVolume3,
  'maki:karaoke': MakiKaraoke,
  'arcticons:quicklyric': ArcticonsQuicklyric,
  'akar-icons:music-album-fill': AkarMusicAlbumFill,
  'entypo:controller-paus': EntypoControllerPaus,
};

type NumOrStr = number | string;

export type IconifyProps = Omit<SVGProps<SVGSVGElement>, 'ref'> & {
  icon: keyof typeof ICONS | string;
  size?: NumOrStr;   // same semantics as Iconify (mapped to font-size)
  inline?: boolean;  // baseline alignment like Iconify
};

function toCssSize(v?: NumOrStr) {
  if (v === undefined || v === null) return undefined;
  return typeof v === 'number' ? `${v}px` : v;
}

export function Iconify({ icon, size, inline, width, height, style, preserveAspectRatio, ...props }: IconifyProps) {
  const Comp = ICONS[icon as keyof typeof ICONS];

  if (!Comp) {
    if (Env.env() !== 'production') {
      // eslint-disable-next-line no-console
      console.log(`[Iconify] Unknown icon "${icon}". Did you add it to ICONS and install its collection?`);
    }
    return null;
  }

  // Map size -> fontSize (Iconify behavior)
  const fontSize = toCssSize(size ?? (props as any).fontSize);

  // Keep square ratio: if only one dimension is provided, mirror the other.
  const hasDim = width != null || height != null;
  const w = hasDim ? (width ?? height) : undefined;
  const h = hasDim ? (height ?? width) : undefined;

  // Strong inline sizing to defeat global svg { width:100% } rules.
  const cssW = toCssSize(w ?? '1em');
  const cssH = toCssSize(h ?? '1em');

  const computedStyle: React.CSSProperties = {
    display: 'inline-block',
    lineHeight: 1,
    flex: '0 0 auto',
    width: cssW,
    height: cssH,
    ...(fontSize ? { fontSize } : null),
    ...(inline ? { verticalAlign: '-0.125em' } : null),
    ...style, // allow caller overrides last
  };

  return (
    <Comp
      // Attributes as a fallback; inline style above takes precedence over global CSS
      width={w}
      height={h}
      preserveAspectRatio={preserveAspectRatio ?? 'xMidYMid meet'}
      style={computedStyle}
      aria-hidden={props['aria-label'] ? undefined : true}
      {...props}
    />
  );
}
