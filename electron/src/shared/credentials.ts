import { credentialService } from '../services/credential.service';

export async function getUser(username: string) {
  return credentialService.get('baander', username);
}

export async function setUser(username: string, password: string) {
  return credentialService.set('baander', username, password);
}
