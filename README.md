# baander

Media server project

## Development setup

To develop on this project you need:

- [docker](https://www.docker.com/)
- [node.js](https://nodejs.org/en)
- [yarn 1.xx](https://yarnpkg.com/)

### Configuring ddev


## Directory structure

```
.ddev
app/ 
  - Packages
config/
  - Backend configuration
database/
  - factories/
     * Model fakers
  - migrations/
  - seeders/
graphql/
public/
resources/
  app/
    * Frontend source
  views/
    * Blade views
routes/
storage/
stubs/
  * Backend code generation templates
tests/
  * Backend tests
third_party/
  * Third party libraries (usually contains patches)
```
_Generated with https://tree.nathanfriend.io/_
