import { OpenAPI } from '@/api-client/requests';
import { apm } from '@/services/apm.ts';

// Map to store transactions by request URL
const transactionMap = new Map<string, any>();

export function apmTransactionInterceptor() {
  // Request interceptor - start transaction
  OpenAPI.interceptors.request.use(
    async (request) => {
      // Extract the endpoint from the URL
      const url = new URL(request.url || '');
      const endpoint = url.pathname;
      
      // Create a unique ID for this request
      const requestId = `${request.method}-${endpoint}-${Date.now()}`;
      
      // Start a transaction for this API call
      const transaction = apm.startTransaction(`API ${request.method} ${endpoint}`, 'api');
      
      // Add metadata about the request
      transaction?.addLabels({
        method: request.method,
        url: endpoint,
        query: url.search,
      });
      
      // Store the transaction in the map
      transactionMap.set(requestId, { transaction, startTime: Date.now() });
      
      // Add the request ID to the request object for later retrieval
      request.headers = request.headers || {};
      request.headers['X-APM-Request-ID'] = requestId;
      
      return request;
    },
  );
  
  // Response interceptor - end transaction
  OpenAPI.interceptors.response.use(
    async (response) => {
      // Get the request ID from the request headers
      const requestId = response.config?.headers?.['X-APM-Request-ID'];
      
      if (requestId && transactionMap.has(requestId)) {
        const { transaction } = transactionMap.get(requestId);
        
        // Add response metadata
        transaction?.addLabels({
          status: response.status,
          statusText: response.statusText,
          responseTime: Date.now() - transactionMap.get(requestId).startTime,
        });
        
        // End the transaction
        transaction?.end();
        
        // Remove the transaction from the map
        transactionMap.delete(requestId);
      }
      
      return response;
    },
  );
}