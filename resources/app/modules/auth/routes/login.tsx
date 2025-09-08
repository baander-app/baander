import { SyntheticEvent } from 'react';
import { Button, Flex, Text, TextField } from '@radix-ui/themes';
import styles from './login.module.scss';
import { Form } from 'radix-ui';
import { Link } from 'react-router-dom';
import * as Checkbox from '@radix-ui/react-checkbox';
import { useRemember } from '@/modules/auth/remember/use-remember';

export default function Login() {
  const { remember, setRemember, email, setEmail, handleSubmit } = useRemember();

  const onSubmit = async (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();

    const fd = new FormData(event.currentTarget);
    const email = String(fd.get('email') ?? '');
    const password = String(fd.get('password') ?? '');
    const rememberMe = fd.get('remember') === '1';

    await handleSubmit({ email, password }, rememberMe);
  };

  return (
    <Flex direction="row" className={styles.loginContainer}>
      <Flex direction="column" gap="3" className={styles.formContent}>
        <Text size="7" weight="bold" align="center" className={styles.welcomeText}>
          Welcome Back!
        </Text>
        <Text size="4" align="center" color="gray" className={styles.tagline}>
          Sign in to continue to your account
        </Text>

        <Form.Root className={styles.form} onSubmit={onSubmit}>
          <Flex direction="column" gap="3">
            <Form.Field className={styles.Field} name="email">
              <Form.Label className={styles.Label}>Email</Form.Label>
              <Form.Control asChild>
                <TextField.Root
                  type="email"
                  radius="large"
                  size="3"
                  required
                  value={email}
                  onChange={(e) => setEmail((e.target as HTMLInputElement).value)}
                >
                  <TextField.Slot></TextField.Slot>
                </TextField.Root>
              </Form.Control>
            </Form.Field>

            <Form.Field className={styles.Field} name="password">
              <Form.Label className={styles.Label}>Password</Form.Label>
              <Form.Control asChild>
                <TextField.Root type="password" radius="large" size="3" required>
                  <TextField.Slot></TextField.Slot>
                </TextField.Root>
              </Form.Control>
            </Form.Field>

            <Form.Field name="remember" className={styles.Field}>
              <Flex align="center" gap="2" className={styles.rememberRow}>
                <Checkbox.Root
                  id="remember"
                  checked={remember}
                  onCheckedChange={(v) => setRemember(!!v)}
                  className={styles.CheckboxRoot}
                  aria-label="Remember me"
                >
                  <Checkbox.Indicator className={styles.CheckboxIndicator} />
                </Checkbox.Root>
                <Form.Label className={styles.rememberLabel} htmlFor="remember">
                  Remember me
                </Form.Label>
                <input type="hidden" name="remember" value={remember ? '1' : '0'} />
              </Flex>
            </Form.Field>

            <Form.Submit asChild>
              <Button variant="solid" size="3" className={styles.Button}>
                Login
              </Button>
            </Form.Submit>
          </Flex>
        </Form.Root>

        <Flex direction="row" justify="between" className={styles.links}>
          <Link to="/auth/forgot-password">
            <Text size="3">Forgot Password?</Text>
          </Link>

          <Link to="/auth/create-account">
            <Text size="3">Create an Account</Text>
          </Link>
        </Flex>
      </Flex>
    </Flex>
  );
}
