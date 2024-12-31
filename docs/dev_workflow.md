# Dev workflow

First of all you must ensure the docker services have been built. On windows you need to be in a WSL prompt that's docker enabled,
You must also ensure this instance of WSL is connected to Docker Desktop on Windows, otherwise routing will not work properly.

Come back to this document once you've properly setup your environment.

## Starting the environment

First run `make build` this will build the application container defined in [Dockerfile](/Dockerfile). Then you'll
want to run `make start` this will start all docker services.

Once you've seen all services start up without error, you can ssh into the application container to install the composer dependencies:

`make ssh`

`composer install`

After you've completed that, type `exit`

### Start frontend

While the backend services are running, run `vite` or `yarn dev` then visit `https://baander.test`