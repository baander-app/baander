import { AsyncEntry, findCredentialsAsync } from '@napi-rs/keyring';

export type Credential = {
  account: string;
  password: string;
};

export const credentialService = {
  async get(
    service: string,
    account: string,
    signal?: AbortSignal
  ): Promise<string | undefined> {
    const entry = new AsyncEntry(service, account);
    return entry.getPassword(signal);
  },

  async set(
    service: string,
    account: string,
    password: string,
    signal?: AbortSignal
  ): Promise<AsyncEntry> {
    const entry = new AsyncEntry(service, account);
    await entry.setPassword(password, signal);
    return entry;
  },

  async delete(
    service: string,
    account: string,
    signal?: AbortSignal
  ): Promise<boolean> {
    const entry = new AsyncEntry(service, account);
    return entry.deleteCredential(signal);
  },

  async find(
    service: string,
    target?: string | null,
    signal?: AbortSignal
  ): Promise<Credential[]> {
    return findCredentialsAsync(service, target, signal);
  },
};
