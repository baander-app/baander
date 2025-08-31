import { findCredentialsAsync } from '@napi-rs/keyring';

export const credentialService = {
  async find(service: string) {
    return findCredentialsAsync(service)
  }
};
