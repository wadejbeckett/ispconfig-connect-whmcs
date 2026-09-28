#!/bin/sh
# PHP 8.2 (repo readiness runtime) + the MySQL extensions it ships but does not enable.
exec /tmp/claude-0/-home-user-ispconfig-connect-whmcs/3b4b6608-32bb-51b3-a26d-ea850c8c07e5/scratchpad/readiness/runtime/php8.2 -d extension=mysqlnd -d extension=pdo_mysql -d extension=mysqli -d extension=sockets "$@"
