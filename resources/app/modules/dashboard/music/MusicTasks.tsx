import { Button, Container, Flex, Heading } from '@radix-ui/themes';

export function MusicTasks() {
  return (
    <Container mt="3">
      <Heading>Tasks</Heading>

      <Flex mt="3">
        <Button>Sync smart playlists</Button>
      </Flex>
    </Container>
  )
}