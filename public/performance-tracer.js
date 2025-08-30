// Simple performance tracer for AudioWorklets and Workers
class PerformanceTracer {
  constructor(name, reportInterval = 5000) {
    this.name = name;
    this.reportInterval = reportInterval;
    this.metrics = {
      processCallCount: 0,
      totalProcessTime: 0,
      maxProcessTime: 0,
      memoryAllocations: 0,
      wasmCalls: 0,
      messagesSent: 0,
      lastReport: performance.now()
    };

    this.startReporting();
  }

  startTrace(operation) {
    return performance.now();
  }

  endTrace(operation, startTime) {
    const duration = performance.now() - startTime;

    if (operation === 'process') {
      this.metrics.processCallCount++;
      this.metrics.totalProcessTime += duration;
      this.metrics.maxProcessTime = Math.max(this.metrics.maxProcessTime, duration);
    } else if (operation === 'wasm') {
      this.metrics.wasmCalls++;
    } else if (operation === 'message') {
      this.metrics.messagesSent++;
    }

    return duration;
  }

  recordMemoryAllocation() {
    this.metrics.memoryAllocations++;
  }

  startReporting() {
    setInterval(() => {
      const now = performance.now();
      const timeSinceLastReport = now - this.metrics.lastReport;

      const avgProcessTime = this.metrics.processCallCount > 0
                             ? this.metrics.totalProcessTime / this.metrics.processCallCount
                             : 0;

      const processCallsPerSecond = (this.metrics.processCallCount / timeSinceLastReport) * 1000;

      console.log(`[${this.name}] Performance Report:`, {
        processCallsPerSecond: processCallsPerSecond.toFixed(1),
        avgProcessTime: avgProcessTime.toFixed(3) + 'ms',
        maxProcessTime: this.metrics.maxProcessTime.toFixed(3) + 'ms',
        totalWasmCalls: this.metrics.wasmCalls,
        messagesSent: this.metrics.messagesSent,
        memoryAllocations: this.metrics.memoryAllocations
      });

      // Reset metrics
      this.metrics = {
        processCallCount: 0,
        totalProcessTime: 0,
        maxProcessTime: 0,
        memoryAllocations: 0,
        wasmCalls: 0,
        messagesSent: 0,
        lastReport: now
      };
    }, this.reportInterval);
  }
}

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
  module.exports = PerformanceTracer;
}
if (typeof self !== 'undefined') {
  self.PerformanceTracer = PerformanceTracer;
}
