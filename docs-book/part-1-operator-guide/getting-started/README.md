# Getting Started

These chapters prepare a Bånder installation. They explain the components and
the data to preserve, size the host for a deployment capability and size, and
install the production stack through the deployment preset, which also starts the
background workers that handle scans, metadata and media conversions.

| Guide | Scope |
|-------|-------|
| [Understanding Baander](understanding-baander.md) | The components, the journey from files to playback, what a health result tells you, and which installation a command targets |
| [Deployment Topology](deployment-topology.md) | How the browser path, PostgreSQL behind PgBouncer, Redis, the worker deployment pair, shared storage, and the optional registry fit together |
| [Storage and Data](storage-and-data.md) | What must be preserved across PostgreSQL, Redis, source media, and generated output, plus shared media mount requirements |
| [System Requirements](system-requirements.md) | Host software, choosing a capability and size starting point, sizing media slots, and measured memory and CPU consumption |
| [Installation](installation.md) | Step-by-step installation: production image builds, the private preset configuration, rendering and starting the deployment, the first account, and your first library scan |
