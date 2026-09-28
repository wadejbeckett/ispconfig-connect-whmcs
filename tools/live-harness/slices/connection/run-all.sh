#!/bin/sh
# Full slice run. Usage: run-all.sh [php-binary] [tag]
PHP=${1:-/usr/bin/php8.3}; TAG=${2:-php83}
cd /opt/livetest/connection
export LT_TAG=$TAG
sh srv/stop.sh; sh srv/start.sh
rm -f srv/catcher-hits.log
for s in 00-setup step4-hostname step5-testconnection step6-reduced-grants step7-failed-login step8-transport meta-render; do
  "$PHP" $s.php > "run-$TAG-$s.log" 2>&1
  echo "== $s ($TAG): $(grep -c '^PASS' run-$TAG-$s.log) pass, $(grep -c '^FAIL' run-$TAG-$s.log) fail, php-diag=$(grep -cE 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught' run-$TAG-$s.log)"
done
echo "catcher hits (must be none): $(cat srv/catcher-hits.log 2>/dev/null | wc -l)"
sh srv/stop.sh
echo "panel php-error.log lines: $(wc -l < /opt/ispc/p2/php-error.log 2>/dev/null || echo 0)"
