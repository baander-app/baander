# Dev workflow

First of all you must ensure the docker services have been built. On windows you need to be in a WSL prompt that's docker enabled,
You must also ensure this instance of WSL is connected to Docker Desktop on Windows, otherwise routing will not work properly.

Come back to this document once you've properly setup your environment.

## Glossary

- Application container
  - Container named baander-app in [docker-compose](/docker-compose.yml)
- Scheduler container
  - Container named baander-scheduler in [docker-compose](/docker-compose.yml)
- Frontend
  - React application in [resources/app](/resources/app)

## Starting the environment

First run `make build` this will build the application container defined in [Dockerfile](/Dockerfile).

Create the docker network

```
$ docker network create baander-backtier
```

Then you'll want to run `make start` this will start all docker services.

Once you've seen all services start up without error, you can ssh into the application container to install the composer dependencies:

`make ssh`

`composer install`

To ease the setup of the application in development mode, you can run the artisan script from within the app container:

`php artisan setup:dev`

### Start frontend

While the backend services are running, run `vite` or `yarn dev` then visit `https://baander.test`

### Start websocket

To broadcast events via the websocket, you must open a new terminal, ssh into the application container and then type

`php artisan reverb:start`

This will start the [Laravel Reverb](https://reverb.laravel.com/) websocket server.