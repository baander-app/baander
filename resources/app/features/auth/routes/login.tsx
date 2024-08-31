import { Token } from '@/services/auth/token.ts';
import { useAppDispatch } from '@/store/hooks.ts';
import { setAccessToken, setIsAuthenticated, setRefreshToken } from '@/store/users/auth-slice.ts';
import { AuthService, OpenAPI } from '@/api-client/requests';
import {
  Box,
  Button,
  Flex,
  Paper,
  PasswordInput,
  Text,
  TextInput,
  Title,
} from '@mantine/core';
import { useForm } from '@mantine/form';
import styles from './login.module.scss';
import { VinylSpinAnimation } from '@/components/animations/vinyl-spin-animation/vinyl-spin-animation.tsx';

type LoginInput = {
  email: string;
  password: string;
}

export default function Login() {
  const dispatch = useAppDispatch();

  const form = useForm({
    initialValues: {
      email: '',
      password: '',
    },
    validate: {
      email: (val) => (/^\S+@\S+$/.test(val) ? null : 'Invalid email'),
      password: (val) => (val.length <= 6 ? 'Password should include at least 6 characters' : null),
    },

  });

  const onSubmit = async (formData: LoginInput) => {
    const res = await AuthService.authLogin({
      requestBody: {
        email: formData.email,
        password: formData.password,
      },
    });

    Token.set(res);
    OpenAPI.TOKEN = res.accessToken.token;
    dispatch(setIsAuthenticated(true));
    dispatch(setAccessToken(res.accessToken));
    dispatch(setRefreshToken(res.refreshToken));
  };

  return (
    <Flex direction="row" className={styles.loginContainer}>
      <Paper className={styles.form} radius={0} p={30}>
        <form onSubmit={form.onSubmit(values => onSubmit(values))}>
          <Title order={2} className={styles.title} ta="center" mt="md" mb={50}>
            Login to Bånder
          </Title>

          <TextInput
            required
            label="Email"
            placeholder="user@baander.app"
            value={form.values.email}
            onChange={(e) => form.setFieldValue('email', e.currentTarget.value)}
            error={form.errors.email && 'Invalid email'}
            radius="md"
          />

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

      <Flex justify="center" w="100%">
        <Box p={30}>
          <VinylSpinAnimation
            loop
            play
            className={styles.animation}
          />

          <Text ta="center">Awaiting tunes...</Text>
        </Box>
      </Flex>
    </Flex>
  );
}
