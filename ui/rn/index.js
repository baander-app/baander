import { AppRegistry } from 'react-native';
import { initCrypto } from './src/shared/crypto/platform-rn-init';
import App from './src/app/App';

// Initialize react-native-quick-crypto as the crypto backend
// before any DPoP operations occur (before auth-store loads)
initCrypto();

AppRegistry.registerComponent('Baander', () => App);
