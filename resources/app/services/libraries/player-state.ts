export interface PlayerStateInput {
  isPlaying: boolean;
  volumePercent: number;
  progressMs?: number;
  deviceName?: string;
  deviceType?: string;
}