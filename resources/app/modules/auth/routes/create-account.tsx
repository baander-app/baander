import { Flex, Text } from '@radix-ui/themes';
import styles from './create-account.module.scss';
import { CreateAccountForm } from '@/modules/auth/create-account/create-account-form.tsx';
import { Link } from 'react-router-dom';

export function CreateAccount() {

  return (
    <Flex direction="column" className={styles.container}>
      <Flex direction="column" gap="3" className={styles.formContent}>
        <Text size="7" weight="bold" align="center" className={styles.heading}>
          Create an account
        </Text>

        <CreateAccountForm />

        <Flex direction="row" justify="between" className={styles.links}>
          <Link to="/auth/forgot-password">
            <Text size="3">
              Forgot Password?
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