#!/bin/sh
# Start the step-8 fake servers; each pid goes to <name>.pid, each request log to <name>.log.
cd /opt/livetest/connection/srv
start() { name=$1; addr=$2; router=$3
  nohup /usr/bin/php8.3 -S "$addr" "$router" > "$name.log" 2>&1 &
  echo $! > "$name.pid"; }
start redirect 127.0.0.2:80 redirect.php
start html 127.0.0.3:80 html.php
start catcher 127.0.0.4:8099 catcher.php
start outage 127.0.0.5:80 outage.php
sleep 1
