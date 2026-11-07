# Workers and Background Work

Background processing in Bånder runs outside the web container in an admitted control and execution worker pair managed from the trusted Docker host. Use these guides to understand how work moves from a request through the transactional outbox and Redis streams to a consumer, and how to provision, observe, retire, or recover the worker pair.

| Guide | Scope |
|-------|-------|
| [Background Work](background-work.md) | The control and execution worker pair, queue flow from request to result, scheduled work, native media admission, observation, and worker recovery |
| [Worker Deployment](worker-deployment.md) | How workers function, building the worker image, version 8 manifest and credentials format, and operating workers with `bin/worker-deployment.php` |
