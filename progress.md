# Progress

## Status
In Progress

## Tasks
- [x] Fix MISSING-09: WebSocket reconnection with exponential backoff for PartySyncBus
- [x] Fix MISSING-08: Party WebSocket auth token passed as query parameter
- [x] Fix MISSING-07: Offline→transport integration (cache-first segment serving)
- [x] Fix OPP-05: OfflineStore quality preference for downloads (lowest/highest/renditionId)
- [x] Fix OPP-08: ImmersiveRenderer camera FOV updates in render loop
- [x] Fix sibling session test breakage (ABR content-aware margin changes, manifest DashParseResult)

## Files Changed
- `src/party/PartySyncBus.ts` — WebSocket reconnection (1s→30s backoff), auth token in URL, join args stored for reconnect
- `src/core/transport/AdaptiveTransportLayer.ts` — OfflineStore reference + cache-first check before network fetch, navigator.onLine fast-fail
- `src/offline/OfflineStore.ts` — downloadVideo accepts qualityPreference option ('lowest'|'highest'|renditionId)
- `src/immersive/ImmersiveRenderer.ts` — render() updates camera.fov from spatialState.fov
- `src/BaanderPlayer.ts` — Wires offlineStore into transport via setOfflineStore()
- `tests/unit/abr-extended.test.ts` — Updated gaming test assertion to match content-aware safety margin behavior
- `tests/unit/manifest-dash.test.ts` — Already fixed by sibling session (34 tests)

## Notes
- 156/156 tests passing, 0 TS errors
- PartySyncBus: reconnects with exponential backoff (1s initial, 2x multiplier, 30s max). Resets on successful reconnect. Cancels on explicit leave().
- Transport: before each network fetch, checks offline store. If cached, returns immediately with fromCache=true. If offline and not cached, returns error immediately without network attempt.
- Offline download: defaults to 'highest' quality. Supports 'lowest', 'highest', or a specific rendition ID/name.
