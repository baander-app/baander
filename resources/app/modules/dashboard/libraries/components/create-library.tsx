import { Form } from 'radix-ui';
import { Button, Select, TextField } from '@radix-ui/themes';
import { getLibraryTypesForSelect } from '@/services/libraries/support.ts';
import styles from './create-library.module.css';

export function CreateLibrary() {
  const libraryTypes = getLibraryTypesForSelect();

  return (
    <Form.Root className={styles.Root}>
      <Form.Field className={styles.Field} name="name">
        <Form.Label className={styles.Label}>Name</Form.Label>
        <Form.Control asChild>
          <TextField.Root type="text" radius="large" size="3" required />
        </Form.Control>
        <Form.Message className={styles.Message} match="valueMissing">
          Please enter a name
        </Form.Message>
      </Form.Field>

      <Form.Field className={styles.Field} name="path">
        <Form.Label className={styles.Label}>Filesystem path</Form.Label>
        <Form.Control asChild>
          <TextField.Root type="text" radius="large" size="3" required />
        </Form.Control>
        <Form.Message className={styles.Message} match="valueMissing">
          Please enter the path to the library
        </Form.Message>
      </Form.Field>

      <Form.Field className={styles.Field} name="type">
        <Form.Label className={styles.Label}>Type</Form.Label>
        <Form.Control asChild>
          <Select.Root defaultValue={libraryTypes[0]?.value}>
            <Select.Trigger />
            <Select.Content>
              <Select.Group>
                <Select.Label>Type</Select.Label>
                {libraryTypes.map((type) => (
                  <Select.Item key={type.value} value={type.value}>
                    {type.label}
                  </Select.Item>
                ))}
              </Select.Group>
            </Select.Content>
          </Select.Root>
        </Form.Control>
        <Form.Message className={styles.Message} match="valueMissing">
          Please choose a type
        </Form.Message>
      </Form.Field>

      <Form.Submit asChild>
        <Button className={styles.Button} style={{ marginTop: 10 }}>
          Create Library
        </Button>
      </Form.Submit>
    </Form.Root>
  );
}
