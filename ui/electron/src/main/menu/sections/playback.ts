import type { SectionFactory } from '../types';
import { accel } from '../accelerators';
import { MenuActionId } from '../ids';

export const playbackSection: SectionFactory = ({ t, platform, state }) => {
  const isMac = platform === 'darwin';
  return [
    {
      label: t('menu.playback._'),
      submenu: [
        {
          id: MenuActionId.PlaybackToggle,
          label: state.isPlaying ? t('menu.playback.pause') : t('menu.playback.play'),
          accelerator: accel('togglePlay', isMac),
          enabled: state.canPlay || state.canPause,
        },
        { type: 'separator' as const },
        { id: MenuActionId.PlaybackPrev, label: t('menu.playback.previous'), accelerator: accel('prevTrack', isMac), enabled: state.canPrev },
        { id: MenuActionId.PlaybackNext, label: t('menu.playback.next'), accelerator: accel('nextTrack', isMac), enabled: state.canNext },
        { type: 'separator' as const },
        { id: MenuActionId.PlaybackSeekBackward, label: t('menu.playback.seekBackward'), accelerator: accel('seekBackward', isMac), enabled: state.canSeek },
        { id: MenuActionId.PlaybackSeekForward, label: t('menu.playback.seekForward'), accelerator: accel('seekForward', isMac), enabled: state.canSeek },
      ],
    },
  ];
};
