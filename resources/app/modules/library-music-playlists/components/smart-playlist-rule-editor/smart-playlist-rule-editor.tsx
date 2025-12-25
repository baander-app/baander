import { Button, Flex, Select, TextField, Box } from '@radix-ui/themes';
import { useFieldArray, useWatch, Control, Controller, FieldErrors } from 'react-hook-form';
import { PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import styles from './smart-playlist-rule-editor.module.scss';
import { useState, useEffect } from 'react';
import { useGenresIndex } from '@/app/libs/api-client/gen/endpoints/genre/genre.ts';
import { useArtistsIndex } from '@/app/libs/api-client/gen/endpoints/artist/artist.ts';

const RULE_FIELDS = [
  { value: 'genre', label: 'Genre' },
  { value: 'artist', label: 'Artist' },
  { value: 'year', label: 'Year' },
  { value: 'duration', label: 'Duration' },
];

// Define a type for the valid field values
type FieldType = 'genre' | 'artist' | 'year' | 'duration';

const FIELD_OPERATORS: Record<FieldType, Array<{ value: string; label: string }>> = {
  genre: [
    { value: 'is', label: 'Is' },
    { value: 'isNot', label: 'Is Not' },
  ],
  artist: [
    { value: 'is', label: 'Is' },
    { value: 'isNot', label: 'Is Not' },
  ],
  year: [
    { value: 'is', label: 'Is' },
    { value: 'isNot', label: 'Is Not' },
    { value: 'greaterThan', label: 'Greater Than' },
    { value: 'lessThan', label: 'Less Than' },
    { value: 'between', label: 'Between' },
  ],
  duration: [
    { value: 'is', label: 'Is' },
    { value: 'isNot', label: 'Is Not' },
    { value: 'greaterThan', label: 'Greater Than' },
    { value: 'lessThan', label: 'Less Than' },
    { value: 'between', label: 'Between' },
  ],
};

export type SmartPlaylistRule = {
  field: FieldType;
  operator: string;
  value: string;
  maxValue?: string; // Optional maxValue for 'between' operator
};

export type RuleGroup = {
  operator: 'and' | 'or';
  rules: SmartPlaylistRule[];
};

// Custom Autocomplete component for genre and artist fields
type AutocompleteFieldProps = {
  placeholder: string;
  value: string;
  onChange: (value: string) => void;
  suggestions: { slug: string; name: string }[];
  loading: boolean;
};

function AutocompleteField({ placeholder, value, onChange, suggestions, loading }: AutocompleteFieldProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [inputValue, setInputValue] = useState(value);
  const [filteredSuggestions, setFilteredSuggestions] = useState(suggestions);

  useEffect(() => {
    setInputValue(value);
  }, [value]);

  useEffect(() => {
    if (inputValue) {
      const filtered = suggestions.filter(item => 
        item.name.toLowerCase().includes(inputValue.toLowerCase())
      );
      setFilteredSuggestions(filtered);
    } else {
      setFilteredSuggestions(suggestions);
    }
  }, [inputValue, suggestions]);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;
    setInputValue(newValue);
    setIsOpen(true);
  };

  const handleSelectSuggestion = (suggestion: { slug: string; name: string }) => {
    setInputValue(suggestion.name);
    onChange(suggestion.name);
    setIsOpen(false);
  };

  return (
    <Box position="relative" width="100%">
      <TextField.Root
        placeholder={placeholder}
        value={inputValue}
        onChange={handleInputChange}
        onFocus={() => setIsOpen(true)}
        onBlur={() => setTimeout(() => setIsOpen(false), 200)}
      />
      {isOpen && filteredSuggestions.length > 0 && (
        <div className={styles.autocompleteDropdown}>
          {loading ? (
            <Box p="2" style={{ color: 'var(--gray-1)' }}>Loading...</Box>
          ) : (
            filteredSuggestions.map(suggestion => (
              <div 
                key={suggestion.slug}
                className={styles.suggestionItem}
                onMouseDown={() => handleSelectSuggestion(suggestion)}
              >
                {suggestion.name}
              </div>
            ))
          )}
        </div>
      )}
    </Box>
  );
}

export type SmartPlaylistFormData = {
  rules: RuleGroup[];
};

type SmartPlaylistRuleEditorProps = {
  control: Control<any>;
  name: 'rules' | `rules.${number}`;
  errors?: FieldErrors<any>;
};

export function SmartPlaylistRuleEditor({ control, name, errors }: SmartPlaylistRuleEditorProps) {
  // Initialize rule groups if they don't exist
  const { fields: ruleGroups, append: appendRuleGroup, remove: removeRuleGroup } = useFieldArray({
    control,
    name,
  });

  if (ruleGroups.length === 0) {
    // Add the first rule group with an initial rule
    appendRuleGroup({ 
      operator: 'and', 
      rules: [{ field: 'genre', operator: 'is', value: '', maxValue: '' }] 
    });
  }

  // Function to add a new rule group
  const addRuleGroup = () => {
    appendRuleGroup({ 
      operator: 'and', 
      rules: [{ field: 'genre', operator: 'is', value: '', maxValue: '' }] 
    });
  };

  return (
    <div className={styles.container}>
      {ruleGroups.map((group, groupIndex) => (
        <div key={group.id} className={styles.ruleGroup}>
          {groupIndex > 0 && (
            <Flex justify="center" mb="2">
              <Controller
                name={`${name}.${groupIndex}.operator`}
                control={control}
                render={({ field }) => (
                  <Select.Root
                    value={field.value}
                    onValueChange={(value) => field.onChange(value as 'and' | 'or')}
                  >
                    <Select.Trigger className={styles.logicalOperator}>
                      {field.value === 'and' ? 'AND' : 'OR'}
                    </Select.Trigger>
                    <Select.Content>
                      <Select.Item value="and">AND</Select.Item>
                      <Select.Item value="or">OR</Select.Item>
                    </Select.Content>
                  </Select.Root>
                )}
              />
            </Flex>
          )}

          <Flex justify="between" align="center">
            <h4 className={styles.ruleGroupTitle}>
              {groupIndex === 0 ? 'Match the following rules:' : 'Also match:'}
            </h4>
            {ruleGroups.length > 1 && (
              <Button 
                color="red" 
                variant="soft" 
                size="1"
                onClick={() => removeRuleGroup(groupIndex)}
              >
                <TrashIcon />
              </Button>
            )}
          </Flex>

          <RuleEditor 
            control={control}
            name={`${name}.${groupIndex}.rules`} 
            errors={errors?.rules?.[groupIndex]?.rules} 
          />
        </div>
      ))}

      <Flex justify="center" mt="4">
        <Button variant="soft" onClick={addRuleGroup}>
          <PlusIcon /> Add Rule Group
        </Button>
      </Flex>
    </div>
  );
}

type RuleEditorProps = {
  control: Control<SmartPlaylistFormData>;
  name: string; // Using string to allow for dynamic paths
  errors?: any; // Using any temporarily to fix type issues
};

function RuleEditor({ control, name, errors }: RuleEditorProps) {
  const { fields: rules, append, remove } = useFieldArray({
    control,
    name,
  });

  const watchedFields = useWatch({
    control,
    name,
  }) as SmartPlaylistRule[];

  // Get the current library
  const library = {
    slug: 'music'
  };

  // Fetch genres
  const { data: genresData, isLoading: genresLoading } = useGenresIndex({
    limit: 100,
  });

  // Fetch artists
  const { data: artistsData, isLoading: artistsLoading } = useArtistsIndex(library?.slug || '',{
    limit: 100,
  });

  // Format the data for the autocomplete component
  const genreSuggestions = genresData?.data?.map(genre => ({
    slug: genre.slug,
    name: genre.name,
  })) || [];

  const artistSuggestions = artistsData?.data?.map(artist => ({
    slug: artist.slug,
    name: artist.name,
  })) || [];

  // Function to add a new rule
  const addRule = () => {
    append({ field: 'genre', operator: 'is', value: '', maxValue: '' });
  };

  return (
    <div className={styles.ruleList}>
      {rules.map((rule, ruleIndex) => (
        <Flex key={rule.id} gap="2" align="center" mb="2" className={styles.rule}>
          <Controller
            name={`${name}.${ruleIndex}.field`}
            control={control}
            rules={{ required: 'Field is required' }}
            render={({ field }) => (
              <Select.Root 
                value={field.value || 'genre'}
                onValueChange={(value) => {
                  field.onChange(value);
                }}
              >
                <Select.Trigger className={styles.dropDownTrigger} />
                <Select.Content>
                  {RULE_FIELDS.map((fieldOption) => (
                    <Select.Item key={fieldOption.value} value={fieldOption.value}>
                      {fieldOption.label}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            )}
          />

          <Controller
            name={`${name}.${ruleIndex}.operator`}
            control={control}
            rules={{ required: 'Operator is required' }}
            render={({ field }) => (
              <Select.Root
                value={field.value || 'is'}
                onValueChange={(value) => {
                  field.onChange(value);
                }}
              >
                <Select.Trigger className={styles.dropDownTrigger} />
                <Select.Content>
                  {FIELD_OPERATORS[(watchedFields?.[ruleIndex]?.field || 'genre') as FieldType].map((op) => (
                    <Select.Item key={op.value} value={op.value}>
                      {op.label}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            )}
          />

          {watchedFields?.[ruleIndex]?.operator === 'between' ? (
            <Flex gap="2" className={styles.valueInput}>
              {watchedFields?.[ruleIndex]?.field === 'genre' ? (
                <>
                  <Controller
                    name={`${name}.${ruleIndex}.value`}
                    control={control}
                    rules={{ required: 'Min value is required' }}
                    render={({ field }) => (
                      <AutocompleteField
                        placeholder="Min Genre"
                        value={field.value}
                        onChange={field.onChange}
                        suggestions={genreSuggestions}
                        loading={genresLoading}
                      />
                    )}
                  />
                  <Controller
                    name={`${name}.${ruleIndex}.maxValue`}
                    control={control}
                    rules={{ required: 'Max value is required' }}
                    render={({ field }) => (
                      <AutocompleteField
                        placeholder="Max Genre"
                        value={field.value as string}
                        onChange={field.onChange}
                        suggestions={genreSuggestions}
                        loading={genresLoading}
                      />
                    )}
                  />
                </>
              ) : watchedFields?.[ruleIndex]?.field === 'artist' ? (
                <>
                  <Controller
                    name={`${name}.${ruleIndex}.value`}
                    control={control}
                    rules={{ required: 'Min value is required' }}
                    render={({ field }) => (
                      <AutocompleteField
                        placeholder="Min Artist"
                        value={field.value}
                        onChange={field.onChange}
                        suggestions={artistSuggestions}
                        loading={artistsLoading}
                      />
                    )}
                  />
                  <Controller
                    name={`${name}.${ruleIndex}.maxValue`}
                    control={control}
                    rules={{ required: 'Max value is required' }}
                    render={({ field }) => (
                      <AutocompleteField
                        placeholder="Max Artist"
                        value={field.value as string}
                        onChange={field.onChange}
                        suggestions={artistSuggestions}
                        loading={artistsLoading}
                      />
                    )}
                  />
                </>
              ) : (
                <>
                  <TextField.Root 
                    placeholder="Min"
                    type={(watchedFields?.[ruleIndex]?.field === 'year' || watchedFields?.[ruleIndex]?.field === 'duration') ? "number" : "text"}
                    {...control.register(`${name}.${ruleIndex}.value`, { 
                      required: 'Min value is required'
                    })}
                  />
                  <TextField.Root 
                    placeholder="Max"
                    type={(watchedFields?.[ruleIndex]?.field === 'year' || watchedFields?.[ruleIndex]?.field === 'duration') ? "number" : "text"}
                    {...control.register(`${name}.${ruleIndex}.maxValue`, { 
                      required: 'Max value is required'
                    })}
                  />
                </>
              )}
            </Flex>
          ) : (
            watchedFields?.[ruleIndex]?.field === 'genre' ? (
              <Controller
                name={`${name}.${ruleIndex}.value`}
                control={control}
                rules={{ required: 'Value is required' }}
                render={({ field }) => (
                  <AutocompleteField
                    placeholder="Genre"
                    value={field.value}
                    onChange={field.onChange}
                    suggestions={genreSuggestions}
                    loading={genresLoading}
                  />
                )}
              />
            ) : watchedFields?.[ruleIndex]?.field === 'artist' ? (
              <Controller
                name={`${name}.${ruleIndex}.value`}
                control={control}
                rules={{ required: 'Value is required' }}
                render={({ field }) => (
                  <AutocompleteField
                    placeholder="Artist"
                    value={field.value}
                    onChange={field.onChange}
                    suggestions={artistSuggestions}
                    loading={artistsLoading}
                  />
                )}
              />
            ) : (
              <TextField.Root 
                placeholder="Value"
                type={(watchedFields?.[ruleIndex]?.field === 'year' || watchedFields?.[ruleIndex]?.field === 'duration') && 
                     (watchedFields?.[ruleIndex]?.operator === 'greaterThan' || watchedFields?.[ruleIndex]?.operator === 'lessThan') 
                     ? "number" : "text"}
                className={styles.valueInput}
                {...control.register(`${name}.${ruleIndex}.value`, { 
                  required: 'Value is required'
                })}
              />
            )
          )}

          <Button 
            color="red" 
            variant="soft" 
            size="1"
            onClick={() => remove(ruleIndex)}
          >
            <TrashIcon />
          </Button>
        </Flex>
      ))}

      {errors && (
        <div className={styles.error}>
          {typeof errors === 'string' ? errors : (errors as any).message}
        </div>
      )}

      {rules.map((_rule, index) => (
        errors && errors[index] && (
          <div key={`error-${index}`} className={styles.error}>
            {(errors[index] as any)?.field?.message || (errors[index] as any)?.operator?.message || (errors[index] as any)?.value?.message || (errors[index] as any)?.maxValue?.message}
          </div>
        )
      ))}

      <Flex justify="center" mt="2">
        <Button variant="soft" onClick={addRule}>
          <PlusIcon /> Add Rule
        </Button>
      </Flex>
    </div>
  );
}
