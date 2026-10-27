#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
set -eu
version=8.5.2
checksum=cb75a9b00a2806f7390dd64858ef42a47b443b3475769c8af6af33a18b1381f1
curl --fail --silent --show-error --location --max-time 180 \
    "https://www.php.net/distributions/php-${version}.tar.xz" -o /tmp/php.tar.xz
printf '%s  /tmp/php.tar.xz\n' "$checksum" | sha256sum -c -
mkdir /tmp/php
tar -xJf /tmp/php.tar.xz -C /tmp/php --strip-components=1
cd /tmp/php
./configure --prefix=/opt/php --disable-all --enable-cli --disable-cgi --disable-phpdbg --without-pear
make -j2
make install
