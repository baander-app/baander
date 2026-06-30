import { useState } from 'react'
import styled from 'styled-components'
import { useCreateScheduledJob, useSchedulableCommands } from '../../hooks/use-scheduler-admin'
import type { CreateScheduledJobPayload } from '../../api/scheduler-admin-api'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from '@/shared/components/ui/select'
import { CronExpressionInput } from './CronExpressionInput'

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const FieldGroup = styled.div`
  display: flex;
  flex-direction: column;
`

const Label = styled.label`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const StyledInput = styled(Input)`
  margin-top: 0.25rem;
`

const CommandName = styled.span`
  font-family: monospace;
  font-size: 12px;
`

const CommandDescription = styled.span`
  display: block;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const DescriptionHint = styled.span`
  margin-left: 0.25rem;
  text-transform: none;
  color: var(--color-muted-foreground);
`

export function CreateJobDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolean) => void }) {
  const [name, setName] = useState('')
  const [expression, setExpression] = useState('')
  const [jobType, setJobType] = useState<'messenger' | 'console'>('messenger')
  const [command, setCommand] = useState('')
  const [description, setDescription] = useState('')
  const [params, setParams] = useState<Record<string, unknown>>({})

  const createJob = useCreateScheduledJob()
  const { data: commandsData } = useSchedulableCommands()

  const availableCommands = jobType === 'messenger'
    ? commandsData?.messenger ?? {}
    : commandsData?.console ?? {}
  const selectedCommandDef = availableCommands[command]
  const parameterSchema = selectedCommandDef?.parameters ?? {}

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const payload: CreateScheduledJobPayload = {
      name,
      expression,
      jobType,
      command,
      description: description || null,
      parameters: Object.keys(parameterSchema).length > 0 ? params : undefined,
    }
    createJob.mutate(payload, { onSuccess: () => {
      onOpenChange(false)
      setName(''); setExpression(''); setCommand(''); setDescription(''); setParams({})
    }})
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '32rem' }}>
        <DialogHeader>
          <DialogTitle>Create Scheduled Job</DialogTitle>
          <DialogDescription>Add a new scheduled job to the system.</DialogDescription>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          <FieldGroup>
            <Label>Name</Label>
            <StyledInput value={name} onChange={(e) => setName(e.target.value)} required />
          </FieldGroup>
          <FieldGroup>
            <Label>Schedule</Label>
            <div style={{ marginTop: '0.25rem' }}>
              <CronExpressionInput value={expression} onChange={setExpression} />
            </div>
          </FieldGroup>
          <FieldGroup>
            <Label>Job Type</Label>
            <Select value={jobType} onValueChange={(v) => { setJobType(v as 'messenger' | 'console'); setCommand('') }}>
              <SelectTrigger style={{ marginTop: '0.25rem' }}><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="messenger">Messenger</SelectItem>
                <SelectItem value="console">Console</SelectItem>
              </SelectContent>
            </Select>
          </FieldGroup>
          <FieldGroup>
            <Label>Command</Label>
            <Select value={command || '_none'} onValueChange={(v) => setCommand(v === '_none' ? '' : v)}>
              <SelectTrigger style={{ marginTop: '0.25rem' }}><SelectValue placeholder="Select a command..." /></SelectTrigger>
              <SelectContent>
                <SelectItem value="_none">Select a command...</SelectItem>
                {Object.entries(availableCommands).map(([cmd, def]) => (
                  <SelectItem key={cmd} value={cmd}>
                    <div>
                      <CommandName>{cmd}</CommandName>
                      <CommandDescription>{def.description}</CommandDescription>
                    </div>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FieldGroup>
          <FieldGroup>
            <Label>Description (optional)</Label>
            <StyledInput value={description} onChange={(e) => setDescription(e.target.value)} />
          </FieldGroup>
          {Object.entries(parameterSchema).map(([key, schema]) => (
            <FieldGroup key={key}>
              <Label>
                {key}
                {schema.description && <DescriptionHint>({schema.description})</DescriptionHint>}
              </Label>
              <StyledInput
                type={schema.type === 'integer' ? 'number' : 'text'}
                value={(params[key] ?? schema.default ?? '') as string}
                onChange={(e) =>
                  setParams((prev) => ({
                    ...prev,
                    [key]: schema.type === 'integer' ? (e.target.value === '' ? null : Number(e.target.value)) : e.target.value,
                  }))
                }
                placeholder={schema.default != null ? String(schema.default) : ''}
              />
            </FieldGroup>
          ))}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={createJob.isPending}>
              {createJob.isPending ? 'Creating...' : 'Create Job'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
