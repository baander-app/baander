#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
set -eu
version=8.5.13
checksum=607602acaf0c3061818e9916ec44a058e8b3d43959a83862b9cd3ec7bf5b785f
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
