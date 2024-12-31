import { useWebauthn } from '@/hooks/use-webauthn.ts';
import { usePasskeyServiceAuthPasskeyRegisterOptions } from '@/api-client/queries';
import { useEffect, useState } from 'react';
import { Button, Group, Input, TextInput } from '@mantine/core';
import { AuthService } from '@/api-client/requests';
import { useForm } from '@mantine/form';
import { values } from 'idb-keyval';
import { notifications } from '@mantine/notifications';

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

  const onSubmit =async (values: FormValues) => {
    const options = await AuthService.authPasskeyRegisterOptions();
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

      <Group mt="md">
        <Button type="submit">Create</Button>
      </Group>
    </form>
  );
}