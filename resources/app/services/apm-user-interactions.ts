import { apm } from '@/services/apm.ts';

/**
 * Utility functions for tracking user interactions with APM
 */
export const apmUserInteractions = {
  /**
   * Track a user interaction
   * @param actionName - Name of the action (e.g., 'click', 'submit')
   * @param targetName - Name of the target (e.g., 'login-button', 'search-form')
   * @param metadata - Additional metadata about the interaction
   */
  trackAction: (
    actionName: string,
    targetName: string,
    metadata?: Record<string, any>
  ) => {
    const transaction = apm.startTransaction(
      `User Action: ${actionName} ${targetName}`,
      'user-interaction'
    );

    transaction?.addLabels({
      action: actionName,
      target: targetName,
      timestamp: new Date().toISOString(),
      ...metadata,
    });

    // End the transaction after a short delay to capture any immediate effects
    setTimeout(() => {
      transaction?.end();
    }, 100);

    return transaction;
  },

  /**
   * Track a form submission
   * @param formName - Name of the form
   * @param metadata - Additional metadata about the form submission
   */
  trackFormSubmission: (
    formName: string,
    metadata?: Record<string, any>
  ) => {
    return apmUserInteractions.trackAction('submit', formName, metadata);
  },

  /**
   * Track a button click
   * @param buttonName - Name of the button
   * @param metadata - Additional metadata about the button click
   */
  trackButtonClick: (
    buttonName: string,
    metadata?: Record<string, any>
  ) => {
    return apmUserInteractions.trackAction('click', buttonName, metadata);
  },

  /**
   * Track a search action
   * @param searchTerm - The search term
   * @param metadata - Additional metadata about the search
   */
  trackSearch: (
    searchTerm: string,
    metadata?: Record<string, any>
  ) => {
    return apmUserInteractions.trackAction('search', 'search-box', {
      searchTerm,
      ...metadata,
    });
  },

  /**
   * Track a navigation action (e.g., menu click)
   * @param destination - The destination of the navigation
   * @param metadata - Additional metadata about the navigation
   */
  trackNavigation: (
    destination: string,
    metadata?: Record<string, any>
  ) => {
    return apmUserInteractions.trackAction('navigate', destination, metadata);
  },

  /**
   * Create a custom span for tracking a specific part of a user interaction
   * @param transaction - The parent transaction
   * @param spanName - Name of the span
   * @param spanType - Type of the span
   * @param metadata - Additional metadata for the span
   */
  createActionSpan: (
    transaction: any,
    spanName: string,
    spanType: string,
    metadata?: Record<string, any>
  ) => {
    const span = transaction?.startSpan(spanName, spanType);
    
    if (span && metadata) {
      span.addLabels(metadata);
    }
    
    return span;
  },
};

/**
 * React hook for tracking user interactions
 * @returns Object with tracking functions
 */
export function useApmUserInteractions() {
  return apmUserInteractions;
}