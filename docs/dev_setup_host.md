# Dev setup host

## Install nvm, nodejs + yarn

If you have not install nvm (Node version manager), then you must install it now. The windows version is __NOT__ suitable,
if you're working on Windows, you must as said before be inside a WSL linux shell.

Follow the instructions here [nvm - installing and updating](https://github.com/nvm-sh/nvm?tab=readme-ov-file#installing-and-updating)

Then install nodejs 20+ (22 is also okay)

```shell
nvm install 22.7.0 --with-latest-npm
```

Exit the shell and open it again, so we'll have node/nvm in our environment (you can also follow the on screen instructions).

Install yarn 1

```shell
npm i -g yarn
```

## Hosts file

Map `baander.test` to `127.0.0.1` in your hosts file