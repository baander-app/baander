import { Button, Container, Paper, PasswordInput, Text, Title } from '@mantine/core';
import { useForm } from '@mantine/form';

type PasswordForm = {
  password: string;
}

export function ConfirmPassword() {

  const form = useForm<PasswordForm>({
    initialValues: {
      password: '',
    },
    validate: {
      password: (val) => (val.length <= 6 ? 'Password should include at least 6 characters' : null),
    }
  });

  return (
    <Container>
      <Paper>
        <form>
          <Title>Confirm password</Title>

          <Text>
            This is a secure area of the application. Please confirm your password before continuing.
          </Text>

          <PasswordInput
            required
            label="Password"
            placeholder="******"
            value={form.values.password}
            onChange={(e) => form.setFieldValue('password', e.currentTarget.value)}
            error={form.errors.password && 'Password should include at least 6 characters'}
            radius="md"
          />

          <Button type="submit" fullWidth mt="xl" size="md" radius="xl">
            Login
          </Button>
        </form>
      </Paper>
    </Container>
  )
}