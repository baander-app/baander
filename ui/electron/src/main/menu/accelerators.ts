type AccelMap = Record<string, { darwin?: string; default: string }>;

export const ACCEL: AccelMap = {
  reload: { darwin: 'Cmd+R', default: 'Ctrl+R' },
  toggleDevTools: { darwin: 'Alt+Cmd+I', default: 'Ctrl+Shift+I' },
  toggleFullScreen: { darwin: 'Ctrl+Cmd+F', default: 'F11' },
  nextTrack: { default: 'MediaNextTrack' },
  prevTrack: { default: 'MediaPreviousTrack' },
  togglePlay: { default: 'MediaPlayPause' },
  seekForward: { default: 'Alt+Right' },
  seekBackward: { default: 'Alt+Left' },
};

export function accel(key: keyof typeof ACCEL, isMac: boolean) {
  const a = ACCEL[key];
  return (isMac && a.darwin) ? a.darwin : a.default;
}
