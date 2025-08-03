import { EventMap, EventCallback } from './events';

class EventBridge {
  private listeners: Map<string, Set<EventCallback<any>>> = new Map();
  private onceListeners: Map<string, Set<EventCallback<any>>> = new Map();

  // Subscribe to an event
  on<K extends keyof EventMap>(
    event: K,
    callback: EventCallback<EventMap[K]>
  ): () => void {
    const eventKey = event as string;

    if (!this.listeners.has(eventKey)) {
      this.listeners.set(eventKey, new Set());
    }

    this.listeners.get(eventKey)!.add(callback as EventCallback<any>);

    // Return unsubscribe function
    return () => {
      this.off(event, callback);
    };
  }

  // Subscribe to an event (one-time only)
  once<K extends keyof EventMap>(
    event: K,
    callback: EventCallback<EventMap[K]>
  ): () => void {
    const eventKey = event as string;

    if (!this.onceListeners.has(eventKey)) {
      this.onceListeners.set(eventKey, new Set());
    }

    this.onceListeners.get(eventKey)!.add(callback as EventCallback<any>);

    // Return unsubscribe function
    return () => {
      const listeners = this.onceListeners.get(eventKey);
      if (listeners) {
        listeners.delete(callback as EventCallback<any>);
      }
    };
  }

  // Unsubscribe from an event
  off<K extends keyof EventMap>(
    event: K,
    callback: EventCallback<EventMap[K]>
  ): void {
    const eventKey = event as string;
    const listeners = this.listeners.get(eventKey);
    if (listeners) {
      listeners.delete(callback as EventCallback<any>);
    }
  }

  // Emit an event
  emit<K extends keyof EventMap>(
    event: K,
    data: EventMap[K]
  ): void {
    const eventKey = event as string;

    // Handle regular listeners
    const listeners = this.listeners.get(eventKey);
    if (listeners && listeners.size > 0) {
      listeners.forEach(callback => {
        try {
          callback(data);
        } catch (error) {
          console.error(`Error in event listener for "${eventKey}":`, error);
        }
      });
    }

    // Handle one-time listeners
    const onceListeners = this.onceListeners.get(eventKey);
    if (onceListeners && onceListeners.size > 0) {
      // Create a copy of the set to avoid modification during iteration
      const listenersToCall = Array.from(onceListeners);

      // Clear once listeners before execution to prevent issues with re-entry
      this.onceListeners.delete(eventKey);

      listenersToCall.forEach(callback => {
        try {
          callback(data);
        } catch (error) {
          console.error(`Error in once event listener for "${eventKey}":`, error);
        }
      });
    }
  }

  // Remove all listeners for an event
  removeAllListeners<K extends keyof EventMap>(event?: K): void {
    if (event) {
      const eventKey = event as string;
      this.listeners.delete(eventKey);
      this.onceListeners.delete(eventKey);
    } else {
      this.listeners.clear();
      this.onceListeners.clear();
    }
  }

  // Get listener count for debugging
  listenerCount<K extends keyof EventMap>(event: K): number {
    const eventKey = event as string;
    const regular = this.listeners.get(eventKey)?.size || 0;
    const once = this.onceListeners.get(eventKey)?.size || 0;
    return regular + once;
  }

  // Get all active events for debugging
  getActiveEvents(): string[] {
    const events = new Set([
      ...this.listeners.keys(),
      ...this.onceListeners.keys()
    ]);
    return Array.from(events);
  }

  // Debug method to see all listeners
  debug(): void {
    console.log('EventBridge Debug Info:');
    console.log('Regular listeners:', Object.fromEntries(
      Array.from(this.listeners.entries()).map(([key, set]) => [key, set.size])
    ));
    console.log('Once listeners:', Object.fromEntries(
      Array.from(this.onceListeners.entries()).map(([key, set]) => [key, set.size])
    ));
  }
}

// Export singleton instance
export const eventBridge = new EventBridge();

// Export the class for testing purposes
export { EventBridge };
