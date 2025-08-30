import { endSessionSpan, startSessionSpan } from '@/libs/tracing/start-session-span.ts';
import { defaultResource, resourceFromAttributes } from '@opentelemetry/resources';
import { WebTracerProvider } from '@opentelemetry/sdk-trace-web';
import { BatchSpanProcessor } from '@opentelemetry/sdk-trace-base';
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { OTLPMetricExporter } from '@opentelemetry/exporter-metrics-otlp-http';
import { ZoneContextManager } from '@opentelemetry/context-zone';
import { registerInstrumentations } from '@opentelemetry/instrumentation';
import { getWebAutoInstrumentations } from '@opentelemetry/auto-instrumentations-web';
import { MeterProvider, PeriodicExportingMetricReader } from '@opentelemetry/sdk-metrics';
import { UserInteractionInstrumentation } from '@opentelemetry/instrumentation-user-interaction';
import { DocumentLoadInstrumentation } from '@opentelemetry/instrumentation-document-load';
import { LongTaskInstrumentation } from '@opentelemetry/instrumentation-long-task';
import { ATTR_SERVICE_NAME, ATTR_SERVICE_VERSION } from '@opentelemetry/semantic-conventions';

// Define resource and service attributes
const resource = defaultResource().merge(
  resourceFromAttributes({
    [ATTR_SERVICE_NAME]: 'baander-web',
    [ATTR_SERVICE_VERSION]: window.BaanderAppConfig.version,
  }),
);

// OTLP Exporters Configuration
const otlpConfig = {
  headers: {
    'signoz-access-token': window.BaanderAppConfig.tracing.token,
  },
};

// Set up exporters
const traceExporter = new OTLPTraceExporter({
  // TODO: handle null case
  url: `${window.BaanderAppConfig.tracing.url}/traces`,
  ...otlpConfig,
});

const metricExporter = new OTLPMetricExporter({
  url: `${window.BaanderAppConfig.tracing.url}/metrics`,
  ...otlpConfig,
});

// Set up processors
const spanProcessor = new BatchSpanProcessor(traceExporter);

// Create providers
const tracerProvider = new WebTracerProvider({
  resource: resource,
  spanProcessors: [spanProcessor],
});

const meterProvider = new MeterProvider({
  resource: resource,
  readers: [
    new PeriodicExportingMetricReader({
      exporter: metricExporter,
      exportIntervalMillis: 30000,
    }),
  ],
});

// Register the tracer provider
tracerProvider.register({
  contextManager: new ZoneContextManager(),
});

// Set up instrumentation with verified packages
registerInstrumentations({
  instrumentations: [
    // Auto instrumentations (includes fetch, xhr, etc.)
    getWebAutoInstrumentations({
      '@opentelemetry/instrumentation-xml-http-request': {
        enabled: true,
        propagateTraceHeaderCorsUrls: [
          // TODO: add app.url
          /^https?:\/\/baander\.test\/(api|webauthn)/,
          /^https?:\/\/localhost:\d+\/(api|webauthn)/,
        ],
      },
      '@opentelemetry/instrumentation-fetch': {
        enabled: true,
        propagateTraceHeaderCorsUrls: [
          /^https?:\/\/baander\.test\/(api|webauthn)/,
          /^https?:\/\/localhost:\d+\/(api|webauthn)/,
        ],
        applyCustomAttributesOnSpan: (span, request, result) => {
          if ('url' in request && request.url) {
            span.setAttribute('http.url', request.url);
          }
          if (result && 'status' in result && result.status) {
            span.setAttribute('http.status_code', result.status);
          }
        },
      },
    }),

    // User interaction tracking
    new UserInteractionInstrumentation({
      enabled: true,
      eventNames: ['click', 'dblclick', 'mousedown', 'mouseup', 'keydown', 'keyup'],
    }),

    // Document load performance
    new DocumentLoadInstrumentation({
      enabled: true,
    }),

    // Long task tracking
    new LongTaskInstrumentation({
      enabled: true,
    }),
  ],
});

startSessionSpan(crypto.randomUUID());
window.addEventListener('beforeunload', () => endSessionSpan());// Register the tracer provider
tracerProvider.register({
  contextManager: new ZoneContextManager(),
});

// Set up instrumentation with verified packages
registerInstrumentations({
  instrumentations: [
    // Auto instrumentations (includes fetch, xhr, etc.)
    getWebAutoInstrumentations({
      '@opentelemetry/instrumentation-xml-http-request': {
        enabled: true,
        propagateTraceHeaderCorsUrls: [
          /^https?:\/\/baander\.test\/(api|webauthn)/,
          /^https?:\/\/localhost:\d+\/(api|webauthn)/,
        ],
      },
      '@opentelemetry/instrumentation-fetch': {
        enabled: true,
        propagateTraceHeaderCorsUrls: [
          /^https?:\/\/baander\.test\/(api|webauthn)/,
          /^https?:\/\/localhost:\d+\/(api|webauthn)/,
        ],
        applyCustomAttributesOnSpan: (span, request, result) => {
          if ('url' in request && request.url) {
            span.setAttribute('http.url', request.url);
          }
          if (result && 'status' in result && result.status) {
            span.setAttribute('http.status_code', result.status);
          }
        },
      },
    }),

    // User interaction tracking
    new UserInteractionInstrumentation({
      enabled: true,
      eventNames: ['click', 'dblclick', 'mousedown', 'mouseup', 'keydown', 'keyup'],
    }),

    // Document load performance
    new DocumentLoadInstrumentation({
      enabled: true,
    }),

    // Long task tracking
    new LongTaskInstrumentation({
      enabled: true,
    }),
  ],
});

startSessionSpan(crypto.randomUUID());
window.addEventListener('beforeunload', () => endSessionSpan());

// Export providers
export { tracerProvider, meterProvider };
