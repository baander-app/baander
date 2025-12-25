import { Container, Flex, Heading, Text } from '@radix-ui/themes';
import { Env } from '@/app/common/env.ts';

export function DashboardHome() {
  return (
    <Container>
      <Heading mt="3">Bånder</Heading>

      <Flex direction="column" mt="3">
        <Text><Text weight="bold">Application name</Text> {Env.appName()}</Text>
        <Text><Text weight="bold">Environment</Text> {Env.env()}</Text>
        <Text><Text weight="bold">Url</Text> {Env.url()}</Text>
        <Text><Text weight="bold">Version</Text> {Env.version()}</Text>
      </Flex>
    </Container>
  );
}