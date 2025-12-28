import { useSettingsActions, useVolumeNormalization, useTargetLufs } from '@/app/store/settings';
import { usePlayerLufs } from '@/app/modules/library-music-player/store';


export function VolumeNormalization() {
  const { setVolumeNormalization, setTargetLufs } = useSettingsActions();
  const normalization = useVolumeNormalization();
  const targetLufs = useTargetLufs();
  const currentLufs = usePlayerLufs();

  // Compute current gain (derived value)
  const currentGain = targetLufs - currentLufs;

  return (
    <div className="volume-normalization-controls">
      <div className="flex items-center gap-4">
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={normalization.enabled}
            onChange={(e) => setVolumeNormalization(e.target.checked)}
          />
          Volume Normalization
        </label>

        {normalization.enabled && (
          <div className="flex items-center gap-4">
            <label className="flex items-center gap-2">
              Target LUFS:
              <select
                value={targetLufs}
                onChange={(e) => setTargetLufs(parseFloat(e.target.value) as -14 | -16 | -18 | -23)}
              >
                <option value={-14}>-14 LUFS (Spotify)</option>
                <option value={-16}>-16 LUFS (Apple Music)</option>
                <option value={-18}>-18 LUFS (YouTube)</option>
                <option value={-23}>-23 LUFS (Broadcast)</option>
              </select>
            </label>

            <div className="text-sm text-gray-600">
              Current: {currentLufs.toFixed(1)} LUFS
              {Math.abs(currentGain) > 0.1 && (
                <span className="ml-2">
                  (Gain: {currentGain > 0 ? '+' : ''}{currentGain.toFixed(1)} dB)
                </span>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );

}