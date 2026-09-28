#!/bin/sh
# Full run of the usage-sso-hooks slice from a freshly reset panel 6.
#   run-all.sh 83   -> /usr/bin/php8.3
#   run-all.sh 82   -> ./php82.sh (repo readiness PHP 8.2 + mysqlnd/pdo_mysql/mysqli)
set -u
LT=/opt/livetest/usage-sso-hooks
V=${1:-83}
if [ "$V" = 82 ]; then PHP=$LT/php82.sh; else PHP=/usr/bin/php8.3; fi
export LT_PHP=$PHP
cd "$LT" || exit 1

# Step 3 does not depend on PHP or the panel beyond php -l (PATH php).
sh step03-installer.sh > "run-php$V-step03-installer.log" 2>&1

# Reset panel 6: stop its server by pid (never pkill -f), then rebuild it.
if [ -f /opt/ispc/p6/server.pid ]; then
    kill "$(cat /opt/ispc/p6/server.pid)" 2>/dev/null
    sleep 1
fi
/opt/ispc/make-panel.sh 6 8086 > "run-php$V-make-panel.log" 2>&1 || { echo "make-panel failed"; exit 1; }
rm -f state.json
[ -s keys/test-ssl.key ] || { mkdir -p keys; openssl genrsa 2048 2>/dev/null > keys/test-ssl.key; }

for s in 00-setup step19-sso step20-usage step21-metrics step22-servers-page step24-sync; do
    "$PHP" "$s.php" > "run-php$V-$s.log" 2>&1
    echo "php$V $s exit=$? $(grep -E '^SUMMARY' "run-php$V-$s.log")"
done
grep -h -E '^(FAIL|TOTAL)' run-php$V-*.log
