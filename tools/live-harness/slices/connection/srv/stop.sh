#!/bin/sh
cd /opt/livetest/connection/srv
for f in redirect html catcher outage; do [ -f $f.pid ] && kill "$(cat $f.pid)" 2>/dev/null; rm -f $f.pid; done
