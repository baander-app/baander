import { trace, context, Span } from '@opentelemetry/api';

const SESSION_TRACER = trace.getTracer('session-tracer');
let _sessionSpan: Span | null = null;

export function startSessionSpan(sessionId: string): void {
  if (_sessionSpan) return;                        // already started

  _sessionSpan = SESSION_TRACER.startSpan('user-session', {
    attributes: {
      'session.id': sessionId,
      'session.start': new Date().toISOString(),
    },
  });

  /* 🔑 keep the span in the global context so every child span
       automatically becomes part of the same trace */
  context.with(trace.setSpan(context.active(), _sessionSpan), () => {
    // nothing else required – context is now active
  });
}

export function endSessionSpan(): void {
  if (!_sessionSpan) return;
  _sessionSpan.setAttribute('session.end', new Date().toISOString());
  _sessionSpan.end();
  _sessionSpan = null;
}

/* allow other modules to attach user-id, etc. */
export const ROOT_SESSION_SPAN = () => _sessionSpan;