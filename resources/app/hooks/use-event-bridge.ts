import { EventCallback, EventMap } from '@/services/event-bridge/events';
import { useEffect, useCallback, useRef } from 'react';
import { eventBridge } from '@/services/event-bridge/bridge.ts';

export function useEventBridge() {
  const unsubscribersRef = useRef<Array<() => void>>([]);

  const subscribe = useCallback(<K extends keyof EventMap>(
    event: K,
    callback: EventCallback<EventMap[K]>,
    once = false
  ) => {
    const unsubscribe = once
                        ? eventBridge.once(event, callback)
                        : eventBridge.on(event, callback);

    unsubscribersRef.current.push(unsubscribe);

    return unsubscribe;
  }, []);

  const emit = useCallback(<K extends keyof EventMap>(
    event: K,
    data: EventMap[K]
  ) => {
    eventBridge.emit(event, data);
  }, []);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      unsubscribersRef.current.forEach(unsubscribe => unsubscribe());
      unsubscribersRef.current = [];
    };
  }, []);

  return { subscribe, emit };
}

// Specific hook for listening to events
export function useEventListener<K extends keyof EventMap>(
  event: K,
  callback: EventCallback<EventMap[K]>,
  deps: React.DependencyList = []
) {
  useEffect(() => {
    const unsubscribe = eventBridge.on(event, callback);
    return unsubscribe;
  }, [event, ...deps]);
}
