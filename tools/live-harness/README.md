# Live-test harness: a real ISPConfig panel plus WHMCS-shaped MySQL tables

Everything needed to run `docs/LIVE-TEST-PLAN.md` without a WHMCS licence or a reachable
ISPConfig server: a real ISPConfig interface + remote API on MariaDB, and a WHMCS-side harness
that drives the module the way WHMCS does. Results of the 2026-09-28 run and the checkpoint
are in `docs/LIVE-TEST-RESULTS.md`; the raw per-slice output is `results/livetest-full.json`.

This directory is development tooling only. Nothing here ships with the module.

## Rebuild (Ubuntu 24.04 container, as root)

```sh
# 1. MariaDB, and Ubuntu's PHP 8.3 with the extensions ISPConfig's JSON API needs (soap!).
apt-get update && apt-get install -y mariadb-server
V=8.3.6-0ubuntu0.24.04.11   # pin to the archive build if a PPA offers newer ones
apt-get install -y --no-install-recommends php8.3-cli=$V php8.3-common=$V php8.3-soap=$V \
  php8.3-mysql=$V php8.3-mbstring=$V php8.3-xml=$V php8.3-curl=$V php8.3-intl=$V \
  php8.3-opcache=$V php8.3-readline=$V
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld && service mariadb start
printf '[mysqld]\nsql-mode="NO_ENGINE_SUBSTITUTION"\n' > /etc/mysql/mariadb.conf.d/99-ispconfig.cnf
mariadb -e "SET GLOBAL sql_mode='NO_ENGINE_SUBSTITUTION'"     # ISPConfig requires this

# 2. ISPConfig source (the develop branch is the 3.3 line; ispconfig.org is often blocked).
mkdir -p /tmp/isp-src && git clone --depth 1 --branch develop \
  https://github.com/neuroradiology/ispconfig.git /tmp/isp-src/neuroradiology_ispconfig

# 3. A panel instance: N=1 on port 8081 -> /opt/ispc/p1, database dbispconfig1.
mkdir -p /opt/ispc && cp make-panel.sh /opt/ispc/ && /opt/ispc/make-panel.sh 1 8081

# 4. The WHMCS-side harness.
mkdir -p /opt/whmcs-harness && cp -r harness.php capsule.php composer.json schema smoke.php smoke2.php /opt/whmcs-harness/
(cd /opt/whmcs-harness && composer install)
(cd /opt/whmcs-harness && /usr/bin/php8.3 smoke.php)   # Test Connection + Create against the real panel
```

`make-panel.sh <N> <PORT>` creates an isolated instance: remote user `whmcs` /
`Whmcs-Remote-Pass-1` with every function group, master templates Basic (1) and Pro (2),
server 1 (web/mail/db/dns, IP 203.0.113.10), served by `php8.3 -S 127.0.0.1:<PORT>` (pid in
`/opt/ispc/p<N>/server.pid`, PHP errors in `php-error.log`). Re-running it resets the instance.
The ISPConfig server daemon does not run: jobs stay in `sys_datalog`; assert on the interface's
own writes (`client.locked`, `client.tmp_data`, `web_domain.active`, ...).

## Using the harness

`harness.php` (read its header) exposes: `harness_reset_whmcs()`, `harness_add_server()`,
`harness_add_product()`, `harness_add_service()`, `harness_params()` (WHMCS-shaped `$params`,
`status` = `domainstatus`, `model` with a `Service\Properties`-like store),
`harness_call('SuspendAccount', $id)` (applies WHMCS's status change on `success`),
`harness_server_params()`, `harness_panel_rows(sql)` and `harness_log_since()`. Environment:
`HARNESS_DB`, `PANEL_PORT`, `PANEL_DB`, `MODULE_DIR` (defaults: whmcs1, 8081, dbispconfig1,
the repo's module). Run everything with `/usr/bin/php8.3`.

Known gaps (see the results doc): a product change is a bare `UPDATE tblhosting.packageid`
(real WHMCS migrates the custom-field rows first); the model has no `save()`/`username`;
`logModuleCall` masks after JSON-encoding.

## The five slices

`slices/<name>/` holds each agent's scripts and JSON evidence from the run. Each expects its
own panel instance and database (the run used N=2..7 on ports 8082..8087); see `common.php` /
`lib.php` and `run-all.sh` where present, and the `harness_notes` in the results JSON for the
run order.
