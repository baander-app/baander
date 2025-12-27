import { Box, Button, Text } from '@radix-ui/themes';
import { SubmitHandler, useForm } from 'react-hook-form';
import { usePlayerLyricsOffset, usePlayerActions } from '@/app/modules/library-music-player/store';
import styles from './lyrics-settings.module.scss';

interface LyricsSettingsForm {
  offset: number;
}

export function LyricsSettings() {
  const lyricsOffset = usePlayerLyricsOffset();
  const { setLyricsOffset } = usePlayerActions();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LyricsSettingsForm>({
    defaultValues: {
      offset: lyricsOffset,
    },
  });

  const onSubmit: SubmitHandler<LyricsSettingsForm> = (data) => {
    setLyricsOffset(data.offset);
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