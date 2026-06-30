/**
 * Mediator types -- typed action mediator for cross-store communication.
 *
 * Prevents stores from directly importing and mutating each other.
 * Stores register handlers via register(), dispatch actions via dispatch().
 *
 * Institutional learning: duplicate state in two stores causes guaranteed desync.
 * Use mediator for cross-store actions (e.g., 'player:play' from catalog store).
 */

export type MediatorAction =
  | { type: 'player:play'; payload: { trackId: string } }
  | { type: 'player:pause' }
  | { type: 'player:next' }
  | { type: 'player:previous' }
  | { type: 'player:seek'; payload: { position: number } }
  | { type: 'player:setVolume'; payload: { volume: number } }
  | { type: 'auth:logout' }
  | { type: 'catalog:select'; payload: { id: string; type: 'album' | 'artist' | 'song' | 'genre' } }
  | { type: 'catalog:clearSelection' };

export type MediatorHandler<T extends MediatorAction['type']> = (
  action: Extract<MediatorAction, { type: T }>,
) => void | Promise<void>;

interface MediatorState {
  handlers: Map<MediatorAction['type'], Set<MediatorHandler<MediatorAction['type']>>>;
}

const mediatorState: MediatorState = {
  handlers: new Map(),
};

/**
 * Register a handler for a specific action type.
 * Returns an unsubscribe function.
 */
export function register<T extends MediatorAction['type']>(
  type: T,
  handler: MediatorHandler<T>,
): () => void {
  if (!mediatorState.handlers.has(type)) {
    mediatorState.handlers.set(type, new Set());
  }
  mediatorState.handlers.get(type)!.add(handler as MediatorHandler<MediatorAction['type']>);

  // Return unsubscribe function
  return () => {
    mediatorState.handlers.get(type)?.delete(handler as MediatorHandler<MediatorAction['type']>);
  };
}

/**
 * Dispatch an action to all registered handlers.
 */
export async function dispatch<T extends MediatorAction['type']>(action: Extract<MediatorAction, { type: T }>) {
  const handlers = mediatorState.handlers.get(action.type);
  if (!handlers) return;

  const promises = Array.from(handlers).map((handler) =>
    Promise.resolve(handler(action as Extract<MediatorAction, { type: T }>)).catch((err) => {
      console.error(`Mediator handler error for ${action.type}:`, err);
    }),
  );

  await Promise.all(promises);
}
