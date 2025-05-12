import { Form } from 'radix-ui';
import styles from './forgot-password-form.module.scss';
import { Box, Button, Flex, Text, TextField } from '@radix-ui/themes';
import React from 'react';

export function ForgotPasswordForm() {

  const onSubmit = (event: React.SyntheticEvent<HTMLFormElement>) => {

  }

  return (

    <Form.Root className={styles.form} onSubmit={onSubmit}>
      <Flex direction="column" gap="3">

        <Form.Field className={styles.Field} name="email">
          <Form.Label className={styles.Label}>Email</Form.Label>
          <Form.Control asChild>
            <TextField.Root type="email" radius="large" size="3" required>
              <TextField.Slot></TextField.Slot>
            </TextField.Root>
          </Form.Control>
        </Form.Field>

        <Form.Submit asChild>
          <Button variant="solid" size="3" className={styles.Button}>
            Submit
          </Button>
        </Form.Submit>
      </Flex>
    </Form.Root>

  )
}