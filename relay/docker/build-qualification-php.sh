#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
set -eu
php_version=8.5.12
php_checksum=08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209
swoole_version=6.2.4
swoole_checksum=cb41348708cb3a360672cd4ffe84b9a6b4efee61773be7d76a11c39949b95334
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
/opt/php/bin/php -r 'if (PHP_VERSION !== $argv[1] || PHP_ZTS || phpversion("swoole") !== $argv[2]) { exit(1); }' "$php_version" "$swoole_version"
