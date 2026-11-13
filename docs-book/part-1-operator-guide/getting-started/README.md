# Getting Started

Prepare a Bånder instance from source using the development-oriented Compose stack, size the host for your chosen profile, and deploy the separate background workers that handle scans, metadata, and media conversions.

| Guide | Scope |
|-------|-------|
| [Understanding Baander](understanding-baander.md) | The components, the journey from files to playback, what a health result tells you, and which installation a command targets |
| [Deployment Topology](deployment-topology.md) | How the browser path, PostgreSQL behind PgBouncer, Redis, the worker deployment pair, shared storage, and the optional registry fit together |
| [Storage and Data](storage-and-data.md) | What must be preserved across PostgreSQL, Redis, source media, and generated output, plus shared media mount requirements |
| [System Requirements](system-requirements.md) | Host software, choosing a capability and size starting point, sizing media slots, and measured memory and CPU consumption |
| [Source Installation](installation.md) | Step-by-step source installation, network and volume preparation, database and OAuth initialization, worker deployment, and your first library scan |
