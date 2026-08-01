import { useCallback } from 'react'
import styled from 'styled-components'
import { Plus, Trash2 } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
} from '@/shared/components/ui/dropdown-menu'
import { Badge } from '@/shared/components/ui/badge'

const EditorContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const Header = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const HintText = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const EmptyMessage = styled.p`
  padding: 1.5rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const RulesList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const RuleRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-lg);
  background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
  padding: 0.5rem 0.75rem;
  position: relative;
`

const FieldTrigger = styled(Button).attrs({ variant: 'outline', size: 'xs' })`
  min-width: 100px;
`

const OperatorTrigger = styled(Button).attrs({ variant: 'outline', size: 'xs' })`
  min-width: 120px;
`

const ValueInput = styled(Input)`
  height: 1.5rem;
  flex: 1;
  font-size: 0.75rem;
`

const AndLabel = styled.span`
  position: absolute;
  margin-top: -0.25rem;
  font-size: 10px;
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const FIELDS = [
  { value: 'genre', label: 'Genre' },
  { value: 'year', label: 'Year' },
  { value: 'artist', label: 'Artist' },
  { value: 'album', label: 'Album' },
  { value: 'play_count', label: 'Play Count' },
  { value: 'date_added', label: 'Date Added' },
  { value: 'duration', label: 'Duration' },
] as const

const OPERATORS = [
  { value: 'equals', label: 'Equals', needsValue: true },
  { value: 'not_equals', label: 'Does not equal', needsValue: true },
  { value: 'contains', label: 'Contains', needsValue: true },
  { value: 'greater_than', label: 'Greater than', needsValue: true },
  { value: 'less_than', label: 'Less than', needsValue: true },
  { value: 'is_empty', label: 'Is empty', needsValue: false },
  { value: 'is_not_empty', label: 'Is not empty', needsValue: false },
] as const

export interface SmartRule {
  id: string
  field: string
  operator: string
  value: string
}

interface SmartPlaylistEditorProps {
  rules: SmartRule[]
  onChange: (rules: SmartRule[]) => void
  disabled?: boolean
}

let nextId = 1
function generateId(): string {
  return `rule-${nextId++}`
}

export function SmartPlaylistEditor({ rules, onChange, disabled }: SmartPlaylistEditorProps) {
  const addRule = useCallback(() => {
    onChange([...rules, { id: generateId(), field: 'genre', operator: 'equals', value: '' }])
  }, [rules, onChange])

  const updateRule = useCallback(
    (id: string, updates: Partial<SmartRule>) => {
      onChange(rules.map((r) => (r.id === id ? { ...r, ...updates } : r)))
    },
    [rules, onChange],
  )

  const removeRule = useCallback(
    (id: string) => {
      onChange(rules.filter((r) => r.id !== id))
    },
    [rules, onChange],
  )

  return (
    <EditorContainer>
      <Header>
        <HintText>
          Rules are combined with AND logic. Songs must match all rules.
        </HintText>
        <Button
          variant="ghost"
          size="xs"
          onClick={addRule}
          disabled={disabled}
        >
          <Plus size={12} />
          Add rule
        </Button>
      </Header>

      {rules.length === 0 && (
        <EmptyMessage>
          No rules yet. Add a rule to filter songs automatically.
        </EmptyMessage>
      )}

      <RulesList>
        {rules.map((rule, index) => {
          const operator = OPERATORS.find((o) => o.value === rule.operator)
          const needsValue = operator?.needsValue ?? true

          return (
            <RuleRow key={rule.id}>
              {index > 0 && (
                <AndLabel>AND</AndLabel>
              )}

              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <FieldTrigger disabled={disabled}>
                    {FIELDS.find((f) => f.value === rule.field)?.label ?? rule.field}
                  </FieldTrigger>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                  {FIELDS.map((field) => (
                    <DropdownMenuItem
                      key={field.value}
                      onClick={() => updateRule(rule.id, { field: field.value })}
                    >
                      {field.label}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>

              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <OperatorTrigger disabled={disabled}>
                    {operator?.label ?? rule.operator}
                  </OperatorTrigger>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                  {OPERATORS.map((op) => (
                    <DropdownMenuItem
                      key={op.value}
                      onClick={() => updateRule(rule.id, { operator: op.value, value: needsValue && !op.needsValue ? '' : rule.value })}
                    >
                      {op.label}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>

              {needsValue && (
                <ValueInput
                  value={rule.value}
                  onChange={(e) => updateRule(rule.id, { value: e.target.value })}
                  placeholder="Value..."
                  disabled={disabled}
                />
              )}

              {!needsValue && (
                <Badge variant="secondary" style={{ fontSize: '10px' }}>
                  {operator?.label}
                </Badge>
              )}

              <Button
                variant="ghost"
                size="icon-xs"
                onClick={() => removeRule(rule.id)}
                disabled={disabled}
                style={{ color: 'var(--color-muted-foreground)' }}
              >
                <Trash2 size={12} />
              </Button>
            </RuleRow>
          )
        })}
      </RulesList>
    </EditorContainer>
  )
}
