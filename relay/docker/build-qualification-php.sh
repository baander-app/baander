#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
set -eu
php_version=8.5.2
php_checksum=cb75a9b00a2806f7390dd64858ef42a47b443b3475769c8af6af33a18b1381f1
swoole_version=6.2.0
swoole_checksum=b66bf449440c1ba2727314e1e9c4c078978e7def21a02b729bbfec35601ae2ee
curl --fail --silent --show-error --location --max-time 180 \
    "https://www.php.net/distributions/php-${php_version}.tar.xz" -o /tmp/php.tar.xz
printf '%s  /tmp/php.tar.xz\n' "$php_checksum" | sha256sum -c -
mkdir /tmp/php
tar -xJf /tmp/php.tar.xz -C /tmp/php --strip-components=1
cd /tmp/php
./configure --prefix=/opt/php --with-config-file-path=/opt/php/etc \
    --with-config-file-scan-dir=/opt/php/etc/conf.d \
    --disable-all --enable-cli --disable-cgi --disable-phpdbg --without-pear \
    --enable-ctype --enable-filter --enable-sockets --enable-pdo \
    --with-sqlite3 --with-pdo-sqlite --with-openssl
make -j4
make install
mkdir -p /opt/php/etc/conf.d
curl --fail --silent --show-error --location --max-time 180 \
    "https://pecl.php.net/get/swoole-${swoole_version}.tgz" -o /tmp/swoole.tgz
printf '%s  /tmp/swoole.tgz\n' "$swoole_checksum" | sha256sum -c -
mkdir /tmp/swoole
tar -xzf /tmp/swoole.tgz -C /tmp/swoole --strip-components=1
cd /tmp/swoole
/opt/php/bin/phpize
./configure --with-php-config=/opt/php/bin/php-config --enable-sockets --disable-brotli
make -j4
make install
printf 'extension=swoole.so\n' > /opt/php/etc/conf.d/swoole.ini
