
import { useForm, SubmitHandler } from 'react-hook-form';
import { DevTool } from '@hookform/devtools';
import { TextField, Button, Box, Flex, Text } from '@radix-ui/themes';
import { Label } from 'radix-ui';
import styles from './create-account-form.module.scss';
import { useAppDispatch } from '@/store/hooks';
import { createUser } from '@/store/users/auth-slice.ts';


interface AccountForm {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export function CreateAccountForm() {
  const {
    register,
    handleSubmit,
    formState: { errors },
    control,
    setError,
  } = useForm<AccountForm>();
  const dispatch = useAppDispatch();

  const onSubmit: SubmitHandler<AccountForm> = (data) => {
    if (data.password !== data.password_confirmation) {
      setError('password_confirmation', {
        type: 'manual',
        message: 'Passwords do not match',
      });
      return;
    }

    dispatch(createUser(data));
  };

  return (
    <>
      <form className={styles.form} onSubmit={handleSubmit(onSubmit)}>
        <Flex direction="column" gap="3" px="4" className={styles.formContent}>
          <Box className={styles.field}>
            <Label.Root htmlFor="name">Name</Label.Root>
            <TextField.Root
              id="name"
              size="3"
              {...register('name', { required: 'Name is required' })}
            />
            {errors.name && <Text color="red">{errors.name.message}</Text>}
          </Box>

          <Box className={styles.field}>
            <Label.Root htmlFor="email">Email</Label.Root>
            <TextField.Root
              id="email"
              type="email"
              size="3"
              {...register('email', {
                required: 'Email is required',
                pattern: {
                  value: /\S+@\S+\.\S+/,
                  message: 'Entered value does not match email format',
                },
              })}
            />
            {errors.email && <Text color="red">{errors.email.message}</Text>}
          </Box>

          <Box className={styles.field}>
            <Label.Root htmlFor="password">Password</Label.Root>
            <TextField.Root
              id="password"
              type="password"
              size="3"
              {...register('password', { required: 'Password is required' })}
            />
            {errors.password && <Text color="red">{errors.password.message}</Text>}
          </Box>

          <Box className={styles.field}>
            <Label.Root htmlFor="password_confirmation">Confirm Password</Label.Root>
            <TextField.Root
              id="password_confirmation"
              type="password"
              size="3"
              {...register('password_confirmation', {
                required: 'Password confirmation is required',
              })}
            />
            {errors.password_confirmation && (
              <Text color="red">{errors.password_confirmation.message}</Text>
            )}
          </Box>

          <Button mt="4" size="3" type="submit">Create Account</Button>
        </Flex>
      </form>

      <DevTool control={control} />
    </>
  );
}
