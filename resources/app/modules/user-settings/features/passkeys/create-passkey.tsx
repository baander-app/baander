import { useWebauthn } from '@/hooks/use-webauthn.ts';
import { Button, Flex, TextField } from '@radix-ui/themes';
import { AuthService } from '@/api-client/requests';
import { useForm } from 'react-hook-form';
import { Iconify } from '@/ui/icons/iconify.tsx';
import { useEffect } from 'react';

type FormValues = {
  name: string,
}

export function CreatePasskey() {
  const { startRegistration, browserSupportsWebAuthn } = useWebauthn();
  const form = useForm<FormValues>({
  });

  useEffect(() => {
    if (!browserSupportsWebAuthn) {
      alert('WebAuthn is not supported by your browser');
    }
  }, []);

  const onSubmit = async (values: FormValues) => {
    const options = await AuthService.getWebauthnPasskeyRegister();
    // @ts-ignore
    const registration = await startRegistration(options);

    AuthService.postWebauthnPasskeyRegister({
      requestBody: {
        name: values.name,
        passkey: JSON.stringify(registration),
      },
    }).then((res) => {
      console.log(res);
    });
  };

  return (
    <form
      onSubmit={form.handleSubmit(onSubmit)}
    >
      <Flex gap="2">
        <TextField.Root
          {...form.register('name')}
        >
          <TextField.Slot>
            <Iconify icon="ion:key-outline" />
          </TextField.Slot>
        </TextField.Root>

        <Flex mt="md">
          <Button type="submit">Create</Button>
        </Flex>
      </Flex>
    </form>
  );
}