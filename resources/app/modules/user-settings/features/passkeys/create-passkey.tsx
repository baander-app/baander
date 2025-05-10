import { useWebauthn } from '@/hooks/use-webauthn.ts';
import { Button, Flex } from '@radix-ui/themes';
import { AuthService } from '@/api-client/requests';

type FormValues = {
  name: string,
}

export function CreatePasskey() {
  const { startRegistration } = useWebauthn();
  const form = useForm<FormValues>({
    mode: 'uncontrolled',
    initialValues: {
      name: '',
    },
    validate: {
      // name: value => value && value.length < 255 || 'Invalid name',
    },
  });

  const onSubmit = async (values: FormValues) => {
    const options = await AuthService.authPasskeyRegisterOptions();
    // @ts-ignore
    const registration = await startRegistration(options);

    AuthService.authPasskeyRegister({
      requestBody: {
        name: values.name,
        passkey: JSON.stringify(registration),
      },
    }).then((res) => {
      notifications.show({
        title: 'Success',
        message: 'Passkey registered successfully',
      });
      console.log(res);
    });
  };

  return (
    <form
      onSubmit={form.onSubmit(onSubmit)}
    >
      <TextInput
        withAsterisk
        label="Name"
        key={form.key('name')}
        {...form.getInputProps('name')}
      />

      <Flex mt="md">
        <Button type="submit">Create</Button>
      </Flex>
    </form>
  );
}