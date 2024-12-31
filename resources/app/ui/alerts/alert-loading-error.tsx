import { Alert, Button } from '@mantine/core';
import { isPromise } from '@/utils/is-promise.ts';
import { useCallback } from 'react';

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
      variant="light"
      color="red"
      title="Request failed"
    >
      {message ?? defaultMessage}

      {retry && (
        <Button onClick={() => retryRequest()}>Retry</Button>
      )}
    </Alert>
  )
}