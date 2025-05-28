import { Button, Flex, Switch, Text, TextField } from '@radix-ui/themes';
import { useForm } from 'react-hook-form';

interface PlaylistForm {
  name: string;
  description: string;
  public: string;
}

export function CreatePlaylist() {
  const {
    // @ts-expect-error
    register,
    // @ts-expect-error
    handleSubmit,
    // @ts-expect-error
    formState: { errors },
  } = useForm<PlaylistForm>();

  return (
    <form>
      <Flex direction="column" gap="3">
        <label>
          <Text as="div" size="2" mb="1" weight="bold">
            Name
          </Text>
          <TextField.Root
            data-1p-ignore
            placeholder="Playlist name"
          />
        </label>

        <label>
          <Text as="div" size="2" mb="1" weight="bold">
            Description
          </Text>
          <TextField.Root
            data-1p-ignore
            placeholder="Optional description"
          />
        </label>

        <label>
          <Text as="div" size="2" mb="1" weight="bold">
            Public
          </Text>
          <Switch />
        </label>
      </Flex>

      <Flex justify="end">
        <Button type="submit">Create</Button>
      </Flex>
    </form>
  )
}