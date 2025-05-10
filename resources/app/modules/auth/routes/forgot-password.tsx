import { Flex, Text } from '@radix-ui/themes';
import { Link } from 'react-router-dom';
import { ForgotPasswordForm } from '@/modules/auth/forgot-password-form/forgot-password-form.tsx';
import styles from './forgot-password.module.scss';

export function ForgotPassword() {

  return (
    <Flex direction="column" className={styles.container}>


      <Flex direction="column" gap="3" className={styles.formContent}>
        <Text size="7" weight="bold" align="center" className={styles.heading}>
          Forgot Password?
        </Text>

        <ForgotPasswordForm />

        <Flex direction="row" justify="between" className={styles.links}>
          <Link to="/auth/create-account">
            <Text size="3">
              Create account
            </Text>
          </Link>

          <Link to="/">
            <Text size="3">
              Login
            </Text>
          </Link>
        </Flex>
      </Flex>
    </Flex>
  )
}