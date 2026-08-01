/**
 * Shared Axios instance utilities.
 *
 * Provides helper functions for building DPoP-authenticated API clients.
 * Does NOT export a pre-configured Axios instance -- each app (web, RN)
 * creates its own with app-specific auth store integration.
 *
 * The original approach of coupling the shared client to a specific auth
 * store creates circular imports and platform coupling. Instead:
 *
 *   shared package: crypto primitives, DPoP proof generation, key management
 *   web app: Axios instance with Zustand auth store + window.__BAANDER_API_URL__
 *   RN app: Axios instance with Zustand auth store + native config
 */

export { createDpopProof } from '../dpop/proof';
export { getDpopKeyPair, getDpopNonce, setDpopNonce } from '../dpop/store';
