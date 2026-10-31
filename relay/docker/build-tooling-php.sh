#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
set -eu
version=8.5.12
checksum=08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209
curl --fail --silent --show-error --location --max-time 180 \
    "https://www.php.net/distributions/php-${version}.tar.xz" -o /tmp/php.tar.xz
printf '%s  /tmp/php.tar.xz\n' "$checksum" | sha256sum -c -
mkdir /tmp/php
tar -xJf /tmp/php.tar.xz -C /tmp/php --strip-components=1
cd /tmp/php
./configure --prefix=/opt/php --disable-all --enable-cli --disable-cgi --disable-phpdbg --without-pear
make -j2
make install
/opt/php/bin/php -r 'if (PHP_VERSION !== $argv[1] || PHP_ZTS) { exit(1); }' "$version"
