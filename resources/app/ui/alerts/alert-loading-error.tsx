import { isPromise } from '@/utils/is-promise.ts';
import { useCallback } from 'react';
import Alert from '@/ui/alerts/alert.tsx';

export interface AlertLoadingErrorProps {
  message?: string;
  retry?: () => void | Promise<void>;
}
export function AlertLoadingError({retry, message}: AlertLoadingErrorProps) {
  const defaultMessage = 'Unable to load request';

  const retryRequest = useCallback(() => {
    if (!retry) {
      return;
    }

    if (isPromise(retry)) {
      retry.then()
    } else {
      retry()
    }
  }, [retry])

  return (
    <Alert
      color="red"
    >
      <Alert.Title>{message ?? defaultMessage}</Alert.Title>

      <Alert.Action onClick={retryRequest}>
        Retry
      </Alert.Action>
    </Alert>
  )
}