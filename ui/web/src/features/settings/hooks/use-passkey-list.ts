import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance';
import { deletePasskeyDelete } from '@/shared/api-client/gen/endpoints';

export interface PasskeyInfo {
  publicId: string;
  name: string;
  createdAt: string;
  lastUsedAt: string | null;
}

async function fetchPasskeys(): Promise<PasskeyInfo[]> {
  const response = await AXIOS_INSTANCE.get('/api/auth/passkey');
  return response.data.data;
}

const PASSKEYS_QUERY_KEY = ['passkeys'] as const;

export function usePasskeyList() {
  return useQuery({
    queryKey: PASSKEYS_QUERY_KEY,
    queryFn: fetchPasskeys,
  });
}

export function useDeletePasskey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const result = await deletePasskeyDelete(publicId);
      return result;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: PASSKEYS_QUERY_KEY });
    },
  });
}
