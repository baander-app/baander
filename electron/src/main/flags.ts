import { app } from 'electron';

export const setCliFlags = (): void => {
  const flags: Record<string, string | null> = {
    'disable-gpu': null,
    'enable-font-antialiasing': null,
    'font-render-hinting': 'none',
    'high-dpi-support': '1',
    'force-device-scale-factor': '1',
    'max-connections-per-server': '10',
    'allow-file-access-from-files': null,
  };

  const features: string[] = [
    'SkiaRenderer',
    'DWriteFontProxy',
    'AggressiveDomStorageFlushing',
    'WinDelaySpellcheckServiceInit',
    'SharedArrayBuffer',
  ];

  switch (process.platform) {
    case 'darwin':
      features.push('CoreAudioExclusiveMode', 'Metal');
      flags['disable-features'] = 'BackgroundThreadedWebGL';
      break;

    case 'linux':
      features.push('PulseaudioLoopbackForWebRTC', 'WaylandFractionalScaleV1');
      flags['font-render-hinting'] = 'slight';
      flags['ozone-platform-hint'] = 'auto';
      flags['disable-gpu-watchdog'] = null;
      break;

    case 'win32':
      features.push('WasapiExclusiveMode');
      break;
  }

  flags['enable-features'] = features.join(',');

  Object.entries(flags).forEach(([key, value]) =>
    app.commandLine.appendSwitch(key, value ?? undefined),
  );
};
