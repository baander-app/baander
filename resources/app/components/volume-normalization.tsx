import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import { setTargetLufs, setVolumeNormalization } from '@/store/music/music-player-slice.ts';


export function VolumeNormalization() {
  const dispatch = useAppDispatch();
  const { enabled, targetLufs, currentGain } = useAppSelector(state => state.musicPlayer.volume.normalization);
  const currentLufs = useAppSelector(state => state.musicPlayer.analysis.lufs);

  return (
    <div className="volume-normalization-controls">
      <div className="flex items-center gap-4">
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={enabled}
            onChange={(e) => dispatch(setVolumeNormalization(e.target.checked))}
          />
          Volume Normalization
        </label>

        {enabled && (
          <div className="flex items-center gap-4">
            <label className="flex items-center gap-2">
              Target LUFS:
              <select
                value={targetLufs}
                onChange={(e) => dispatch(setTargetLufs(parseFloat(e.target.value)))}
              >
                <option value={-14}>-14 LUFS (Spotify)</option>
                <option value={-16}>-16 LUFS (Apple Music)</option>
                <option value={-18}>-18 LUFS (YouTube)</option>
                <option value={-23}>-23 LUFS (Broadcast)</option>
              </select>
            </label>

            <div className="text-sm text-gray-600">
              Current: {currentLufs.toFixed(1)} LUFS
              {currentGain !== 0 && (
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