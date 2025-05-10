import React, { SyntheticEvent } from 'react';
import { Token } from '@/services/auth/token.ts';
import { useAppDispatch } from '@/store/hooks.ts';
import { setAccessToken, setIsAuthenticated, setRefreshToken } from '@/store/users/auth-slice.ts';
import { AuthService, OpenAPI } from '@/api-client/requests';
import { Box, Button, Flex, Text, TextField } from '@radix-ui/themes';
import styles from './login.module.scss';
import { VinylSpinAnimation } from '@/ui/animations/vinyl-spin-animation/vinyl-spin-animation.tsx';
import { Form } from 'radix-ui';

export default function Login() {
  const dispatch = useAppDispatch();

  const onSubmit = async (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();

    const formData = event.target;
    const email = formData.email.value;
    const password = formData.password.value;

    try {
      const res = await AuthService.postApiAuthLogin({
        requestBody: { email, password },
      });

      Token.set(res);
      OpenAPI.TOKEN = res.accessToken.token;
      dispatch(setIsAuthenticated(true));
      dispatch(setAccessToken(res.accessToken));
      dispatch(setRefreshToken(res.refreshToken));
    } catch {
      return;
    }
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
            <VinylSpinAnimation className={styles.animation} />
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
          <a>
            <Text size="3">
              Forgot Password?
            </Text>
          </a>

          <a>
            <Text size="3">
              Create an Account
            </Text>
          </a>
        </Flex>
      </Flex>
    </Flex>
  );
}