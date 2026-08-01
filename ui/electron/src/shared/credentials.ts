import { credentialService } from '../services/credential.service';

const SERVICE = 'app.baander.desktop.credential';

export async function getUser(username: string) {
  return credentialService.get(SERVICE, username);
}

export async function setUser(username: string, password: string) {
  return credentialService.set(SERVICE, username, password);
}

export async function clearUser(username: string) {
  return credentialService.delete(SERVICE, username);
}
