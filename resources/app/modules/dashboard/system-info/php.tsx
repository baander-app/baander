import { Card, Container, Grid, Text, Heading } from '@radix-ui/themes';

import styles from './php.module.scss';
import { useSystemInfoPhp } from '@/libs/api-client/gen/endpoints/system-info/system-info.ts';

function Section(section: { section: string, values: { key: string, value: string|number|boolean|null }[] }) {
  return (
    <Card>
        <Text weight="medium" size="2">{section.section}</Text>

        <Grid columns="2">
          {section.values.map((item, index) => (
            <Text key={index}><span className={styles.configKey}>{item.key}</span>: {item.value}</Text>
          ))}
        </Grid>
    </Card>
  );
}

export function Php() {
  const { data } = useSystemInfoPhp();

  return (
    <Container>
      <Heading mt="3">Php</Heading>

      <Grid columns="2" mt="3">
        {data?.map((section, index) => (
          <Section section={section.section} values={section.values} key={index}/>
        ))}
      </Grid>
    </Container>
  )
}