import { useSystemInfoServiceSystemInfoPhp } from '@/api-client/queries';
import { Card, Container, SimpleGrid, Text, Title } from '@mantine/core';

import styles from './php.module.scss';

function Section(section: { section: string, values: { key: string, value: string|number|boolean|null }[] }) {
  return (
    <Card withBorder shadow="sm" radius="md">
      <Card.Section p="md">
        <Text fw={500} fz="h2">{section.section}</Text>
      </Card.Section>
      <Card.Section mt="sm">
        <SimpleGrid cols={2} spacing="md" p="md">
          {section.values.map((item, index) => (
            <Text key={index}><span className={styles.configKey}>{item.key}</span>: {item.value}</Text>
          ))}
        </SimpleGrid>
      </Card.Section>
    </Card>
  );
}

export function Php() {
  const { data } = useSystemInfoServiceSystemInfoPhp();

  return (
    <Container fluid>
      <Title>Php</Title>

      <SimpleGrid cols={2} spacing="md" mt="lg">
        {data?.map((section, index) => (
          <Section section={section.section} values={section.values} key={index}/>
        ))}
      </SimpleGrid>
    </Container>
  )
}