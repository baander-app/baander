import { SyntheticEvent } from 'react';
import { useAppDispatch } from '@/store/hooks.ts';
import { loginUser } from '@/store/users/auth-slice.ts';
import { Box, Button, Flex, Text, TextField } from '@radix-ui/themes';
import styles from './login.module.scss';
import { VinylSpinAnimation } from '@/ui/animations/vinyl-spin-animation/vinyl-spin-animation.tsx';
import { Form } from 'radix-ui';
import { Link } from 'react-router-dom';

export default function Login() {
  const dispatch = useAppDispatch();

  const onSubmit = async (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();

    const formData = event.target;
    // @ts-expect-error
    const email = formData.email.value;
    // @ts-expect-error
    const password = formData.password.value;

    dispatch(loginUser({ email, password }));
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
          <Box className={styles.animationSection}>
            <VinylSpinAnimation className={styles.animation}/>
          </Box>

          <Flex direction="column" gap="3">
            <Form.Field className={styles.Field} name="email">
              <Form.Label className={styles.Label}>Email</Form.Label>
              <Form.Control asChild>
                <TextField.Root type="email" radius="large" size="3" required>
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

            <Form.Submit asChild>
              <Button variant="solid" size="3" className={styles.Button}>
                Login
              </Button>
            </Form.Submit>
          </Flex>
        </Form.Root>

        <Flex direction="row" justify="between" className={styles.links}>
          <Link to="/auth/forgot-password">
            <Text size="3">
              Forgot Password?
            </Text>
          </Link>

          <Link to="/auth/create-account">
            <Text size="3">
              Create an Account
            </Text>
          </Link>
        </Flex>
      </Flex>
    </Flex>
  );
}