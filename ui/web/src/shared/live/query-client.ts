import { CanceledError } from 'axios'
import { withLiveReadSignal } from './http-admission'
import type { LiveQueryCoordinator } from './query-coordinator'
import { MutationCache, QueryClient, type MutationFunctionContext } from '@tanstack/react-query'
import {
  assertCurrentOwnership,
  bindOwnershipToSignal,
  captureOwnership,
  isCurrentOwnership,
  subscribeOwnership,
  waitForOwnershipAuthority,
  type OwnershipStamp,
} from './ownership'

const executionOwners = new WeakMap<MutationFunctionContext, OwnershipStamp>()
const wrappedOptions = new WeakSet<object>()
/** Fences hook options at execution time, including callbacks retained by existing observers. */
export class OwnedQueryClient extends QueryClient {
  coordinator: LiveQueryCoordinator | null = null

  setCoordinator(coordinator: LiveQueryCoordinator): void {
    this.coordinator = coordinator
  }
  private readonly owner = captureOwnership().principal
  private unsubscribeOwnership: (() => void) | null = null

  constructor() {
    super({
      mutationCache: new MutationCache({
        onMutate: (_variables, _mutation, context) => {
          executionOwners.set(context, captureOwnership())
        },
      }),
      defaultOptions: { queries: { staleTime: 5 * 60 * 1000, retry: 1 } },
    })
  }

  attach(): () => void {
    this.unsubscribeOwnership?.()
    this.unsubscribeOwnership = subscribeOwnership(() => {
      this.cancelQueries().catch(() => undefined)
      for (const query of this.getQueryCache().getAll()) {
        query.setState({ data: undefined, dataUpdatedAt: 0, error: null, errorUpdatedAt: 0, status: 'pending', fetchStatus: 'idle' })
      }
      this.clear()
    })
    return () => {
      this.unsubscribeOwnership?.()
      this.unsubscribeOwnership = null
    }
  }

  dispose(): void {
    this.unsubscribeOwnership?.()
    this.cancelQueries().catch(() => undefined)
    this.clear()
  }

  override defaultQueryOptions: QueryClient['defaultQueryOptions'] = (options) => {
    const resolved = super.defaultQueryOptions(options)
    if (wrappedOptions.has(resolved) || typeof resolved.queryFn !== 'function') return resolved
    const fn = resolved.queryFn
    const coordinated = this.coordinator?.matches(resolved.queryKey) ?? false
    const queryFn: typeof fn = async (context) => {
      const stamp = captureOwnership()
      if (stamp.principal !== this.owner) throw new Error('Query belongs to another principal')
      bindOwnershipToSignal(context.signal, stamp)
      const foreground = context.meta?.livePriority === 'foreground'
      if (coordinated) this.coordinator?.acquireRead(context.queryKey, context.signal, foreground)
      await waitForOwnershipAuthority(context.signal)
      assertCurrentOwnership(stamp)
      this.coordinator?.readStarted(context.queryKey, context.signal)
      const result = await withLiveReadSignal(context.signal, () => fn(context))
      if (context.signal.aborted) throw new CanceledError('Query was cancelled')
      assertCurrentOwnership(stamp)
      return result
    }
    const wrapped = coordinated
      ? {
          ...resolved,
          queryFn,
          refetchOnMount: false,
          refetchOnWindowFocus: false,
          refetchOnReconnect: false,
          refetchInterval: false as const,
          staleTime: resolved.staleTime === 'static' ? 0 : resolved.staleTime,
        }
      : { ...resolved, queryFn }
    wrappedOptions.add(wrapped)
    return wrapped
  }

  override defaultMutationOptions: QueryClient['defaultMutationOptions'] = (options) => {
    const resolved = super.defaultMutationOptions(options)
    if (wrappedOptions.has(resolved)) return resolved
    const wrapped = { ...resolved }
    const stampFor = (context: MutationFunctionContext) => {
      let stamp = executionOwners.get(context)
      if (!stamp) {
        stamp = captureOwnership()
        executionOwners.set(context, stamp)
      }
      return stamp
    }
    if (resolved.mutationFn) {
      const fn = resolved.mutationFn
      wrapped.mutationFn = async (variables, context) => {
        const stamp = stampFor(context)
        assertCurrentOwnership(stamp)
        if (stamp.principal !== this.owner) throw new Error('Mutation belongs to another principal')
        await waitForOwnershipAuthority()
        assertCurrentOwnership(stamp)
        const result = await fn(variables, context)
        assertCurrentOwnership(stamp)
        return result
      }
    }
    if (resolved.onMutate) {
      const callback = resolved.onMutate
      wrapped.onMutate = (variables, context) => {
        if (stampFor(context).principal === this.owner && isCurrentOwnership(stampFor(context))) {
          return callback(variables, context)
        }
      }
    }
    if (resolved.onSuccess) {
      const callback = resolved.onSuccess
      wrapped.onSuccess = (data, variables, result, context) => {
        if (stampFor(context).principal === this.owner && isCurrentOwnership(stampFor(context))) {
          return callback(data, variables, result, context)
        }
      }
    }
    if (resolved.onError) {
      const callback = resolved.onError
      wrapped.onError = (error, variables, result, context) => {
        if (stampFor(context).principal === this.owner && isCurrentOwnership(stampFor(context))) {
          return callback(error, variables, result, context)
        }
      }
    }
    if (resolved.onSettled) {
      const callback = resolved.onSettled
      wrapped.onSettled = (data, error, variables, result, context) => {
        if (stampFor(context).principal === this.owner && isCurrentOwnership(stampFor(context))) return callback(data, error, variables, result, context)
      }
    }
    wrappedOptions.add(wrapped)
    return wrapped
  }
}
