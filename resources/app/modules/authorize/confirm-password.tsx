import { Button, Card, Container, Heading, Text } from '@radix-ui/themes';


export function ConfirmPassword() {

  // const form = useForm<PasswordForm>({
  //   initialValues: {
  //     password: '',
  //   },
  //   validate: {
  //     password: (val) => (val.length <= 6 ? 'Password should include at least 6 characters' : null),
  //   }
  // });

  return (
    <Container>
      <Card>
        <form>
          <Heading>Confirm password</Heading>

          <Text>
            This is a secure area of the application. Please confirm your password before continuing.
          </Text>


          <Button type="submit" mt="xl">
            Login
          </Button>
        </form>
      </Card>
    </Container>
  )
}