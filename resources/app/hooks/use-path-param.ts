import { useParams } from 'react-router-dom';
import { assertIsDefined } from '@/utils/assert/is-defined.ts';

export function usePathParam<T>() : T {
  const params = useParams() as Partial<T>;

  // Validate that each key in T exists in params
  Object.keys(params).forEach((key) => {
    const typedKey = key as keyof T;
    assertIsDefined(params[typedKey], key);
  });

  return params as T;
}