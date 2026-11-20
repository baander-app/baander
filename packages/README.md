# Workflow packages

The standalone PHP workflows use these packages alongside the application. Each
package README describes its source and responsibilities; Composer manifests declare
the direct dependencies. The [workflow reference](../dev-docs/workflow-packages.md)
covers installation, configuration, commands and checks.

| Package | Responsibility |
| --- | --- |
| [core](baander-core/README.md) | Shared environment names and configuration paths |
| [filesystem](baander-filesystem/README.md) | Filesystem and private state primitives |
| [process](baander-process/README.md) | Process execution and runtime checks |
| [configuration](baander-configuration/README.md) | Workflow settings and image roles |
| [containers](baander-containers/README.md) | Docker transport and resource ownership |
| [images](baander-images/README.md) | Image builds and inventory |
| [deployment](baander-deployment/README.md) | Deployment presets and workers |
| [dev-environment](baander-dev-environment/README.md) | Local development controller and releases |
| [dev-qualification](baander-dev-qualification/README.md) | Disposable fixtures and qualification |
| [dev-ci](baander-dev-ci/README.md) | Architecture, backend checks and CI artifacts |
| [dev-dependencies](baander-dev-dependencies/README.md) | Dependency maintenance |
| [dev-registry](baander-dev-registry/README.md) | Registry qualification helpers |
| [dev-website](baander-dev-website/README.md) | Website rendering and publication |

Packages with `dev-` names are development only. Operational workflows install
their required closure; the application uses core for shared environment names and configuration paths.
