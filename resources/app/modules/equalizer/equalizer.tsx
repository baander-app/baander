import { Grid } from '@radix-ui/themes';
import { vfdFrequencyBars } from '@/modules/equalizer/vfd-frequency-bars.ts';
import { VfdDisplay } from '@/modules/equalizer/components/vfd-display.tsx';
import { Knob } from '@/modules/equalizer/components/knob.tsx';
import { Description } from '@/modules/equalizer/components/description.tsx';
import { EqButton } from '@/modules/equalizer/components/eq-button.tsx';
import { Power } from '@/modules/equalizer/components/power.tsx';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import {
  setIsStereoEnabled,
  setIsEnabled as setIsEqualizerEnabled,
  setIsKaraokeEnabled,
  setMicrophoneBooster,
  setIsMicrophoneEnabled,
  setTrebleBooster,
  setMiddleBooster,
  setBassBooster,
  setBalance, setBarsMode,
} from '@/store/audio/equalizer.ts';
import { setIsMuted, setVolume } from '@/store/music/music-player-slice.ts';

import styles from './equalizer.module.scss';

export function Equalizer() {
  const dispatch = useAppDispatch();

  const {
    isEnabled: isEqualizerEnabled,
    isStereoEnabled: isStereoEnabledEnabled,
    isMicrophoneEnabled: isMicrophoneEnabledEnabled,
    isKaraokeEnabled: isKaraokeEnabledEnabled,
    balance: channelBalanceValue,
    barsMode: barsModeValue,
  } = useAppSelector(state => state.equalizer);

  const { source } = useAppSelector((state) => state.musicPlayer);

  const {
    treble: trebleValue,
    middle: middleValue,
    bass: bassValue,
  } = useAppSelector((state) => state.equalizer.boostThreeBands);

  const { isMuted, level: volumeLevelValue } = useAppSelector(
    (state) => state.musicPlayer.volume,
  );

  const { isRepeatEnabled, isShuffleEnabled } = useAppSelector(
    (state) => state.musicPlayer.mode,
  );

  const {
    isKaraokeEnabled,
    isMicrophoneEnabled,
    isStereoEnabled,
    microphoneBoost,
  } = useAppSelector((state) => state.equalizer);

  const { leftChannel, rightChannel, frequencies } = useAppSelector(
    (state) => state.musicPlayer.analysis,
  );

  const onEqPowerClick = () => {
    setTimeout(() => {
      dispatch(setIsEqualizerEnabled(!isEqualizerEnabled));
    }, 200);
  };

  const onEqStereoClick = () => {
    dispatch(setIsStereoEnabled(!isStereoEnabledEnabled));
  };

  const onEqMuteClick = () => {
    dispatch(setIsMuted(!isMuted));
  };

  const onEqKaraokeClick = () => {
    if (isMicrophoneEnabledEnabled)
      dispatch(setIsKaraokeEnabled(!isKaraokeEnabledEnabled));
  };

  const onEqMicrophoneClick = () => {
    if (!isMicrophoneEnabledEnabled) {
      dispatch(setIsKaraokeEnabled(true));
      dispatch(setMicrophoneBooster(10));
      dispatch(setIsMicrophoneEnabled(true));
    } else {
      dispatch(setMicrophoneBooster(0));
      dispatch(setIsMicrophoneEnabled(false));
    }
  };

  const onEqResetClick = () => {
    dispatch(setTrebleBooster(0));
    dispatch(setMiddleBooster(0));
    dispatch(setBassBooster(0));
    dispatch(setBalance(50));
    dispatch(setBarsMode('bars'));
  };

  const onEqBarsModeClick = () => {
    switch (barsModeValue) {
      case 'bars':
        dispatch(setBarsMode('pointer'));
        break;

      case 'pointer':
        dispatch(setBarsMode('off'));
        break;

      case 'off':
        dispatch(setBarsMode('bars'));
        break;
    }
  };

  const handleVolumeChange = (value: number) => {
    dispatch(setVolume(value));
  };

  const handleTrebleChange = (value: number) => {
    dispatch(setTrebleBooster(value));
  };

  const handleMiddleChange = (value: number) => {
    dispatch(setMiddleBooster(value));
  };

  const handleBassChange = (value: number) => {
    dispatch(setBassBooster(value));
  };

  const handleBalanceChange = (value: number) => {
    dispatch(setBalance(value));
  };

  const handleMicrophoneChange = (value: number) => {
    dispatch(setMicrophoneBooster(value));
  };

  return (
    <Grid className={styles.eqContainer}>
      <Grid.Col span={3} className={styles.gridCol}>
        <Power
          className={styles.power}
          isActive={isEqualizerEnabled}
          handleOnPowerClick={onEqPowerClick}
        />

        <Knob
          isIndicatorsVisible={true}
          className="volume"
          name="Volume"
          leftLabel="MIN"
          rightLabel="MAX"
          isEnabled={isEqualizerEnabled}
          value={volumeLevelValue}
          onChange={handleVolumeChange}
        />
        <div className={`${styles.buttonsContainer} buttons`}>
          <EqButton label="Stereo" handleOnClick={onEqStereoClick}/>
          <EqButton label="Mute" handleOnClick={onEqMuteClick}/>
          <EqButton label="Mic" handleOnClick={onEqMicrophoneClick}/>
          <EqButton label="Karaoke" handleOnClick={onEqKaraokeClick}/>
          <EqButton
            title="Resets knobs and display"
            className={styles.reset}
            label="Reset"
            handleOnClick={onEqResetClick}
          />
          <EqButton
            className={styles.barsMode}
            label="BARS MODE"
            handleOnClick={onEqBarsModeClick}
          />
        </div>

        <Description label="Three band" className="tones"/>
      </Grid.Col>

      <Grid.Col span={4} className={styles.knobCol}>

        <Knob
          className={styles.treble}
          name="Treble"
          leftLabel="L"
          rightLabel="H"
          isEnabled={isEqualizerEnabled}
          onChange={handleTrebleChange}
          value={trebleValue}
        />
        <Knob
          className={styles.middle}
          name="Middle"
          leftLabel="L"
          rightLabel="H"
          isEnabled={isEqualizerEnabled}
          onChange={handleMiddleChange}
          value={middleValue}
        />
        <Knob
          className={styles.bass}
          name="Bass"
          leftLabel="L"
          rightLabel="H"
          isEnabled={isEqualizerEnabled}
          onChange={handleBassChange}
          value={bassValue}
        />
        <Knob
          className={styles.balance}
          name="Balance"
          leftLabel="L"
          rightLabel="R"
          isEnabled={isEqualizerEnabled}
          onChange={handleBalanceChange}
          value={channelBalanceValue}
        />
        <Knob
          className={styles.mic}
          name="Microphone"
          leftLabel="L"
          rightLabel="H"
          value={microphoneBoost}
          onChange={handleMicrophoneChange}
          isEnabled={isEqualizerEnabled && isMicrophoneEnabledEnabled}
        />
      </Grid.Col>


      <Grid.Col span={3}>
        <VfdDisplay
          className="vfd"
          isEnabled={isEqualizerEnabled}
          audioSource={source}
          isMuted={isMuted}
          isRepeatEnabled={isRepeatEnabled}
          isShuffleEnabled={isShuffleEnabled}
          isMicrophoneEnabled={isMicrophoneEnabled}
          leftChannel={leftChannel}
          rightChannel={rightChannel}
          isStereoEnabled={isStereoEnabled}
          isKaraokeEnabled={isKaraokeEnabled}
          frequencies={frequencies}
          frequencyBars={vfdFrequencyBars}
          barsMode={barsModeValue}
        />
      </Grid.Col>

    </Grid>
  );
}