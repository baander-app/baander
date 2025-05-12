import { Box, Button, Text } from '@radix-ui/themes';
import { SubmitHandler, useForm } from 'react-hook-form';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import { setLyricsOffset } from '@/store/music/music-player-slice.ts';
import styles from './lyrics-settings.module.scss';

interface LyricsSettingsForm {
  offset: number;
}

export function LyricsSettings() {
  const { lyrics } = useAppSelector(state => state.musicPlayer);
  const dispatch = useAppDispatch();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LyricsSettingsForm>({
    defaultValues: {
      offset: lyrics.offsetMs,
    },
  });

  const onSubmit: SubmitHandler<LyricsSettingsForm> = (data) => {
    dispatch(setLyricsOffset({ ms: data.offset }));
  };

  return (
    <Box>
      <Text weight="bold">Lyrics settings</Text>

      <form
        className={styles.form}
        onSubmit={handleSubmit(onSubmit)}
      >
        <div className={styles.field}>
          <label>Offset (ms)</label>
          <input
            type="number"
            {...register('offset', { valueAsNumber: true })}
          />
          {errors.offset && <span>Offset must be a number</span>}
        </div>

        <Button type="submit">
          Save
        </Button>
      </form>
    </Box>
  );
}