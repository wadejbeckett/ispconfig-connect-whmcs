<?php

/*
 * ispconfig-sso — panel single sign-on for ISPConfig (companion module).
 * Copyright (c) 2026 Wade Beckett.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version. See ../../../../LICENSE.
 *
 * -----------------------------------------------------------------------------
 * Shared constants and helpers for BOTH halves of the module:
 *   - lib/classes/remote.d/connectsso.inc.php  (mints tokens over the remote API)
 *   - index.php                                (redeems them and logs the user in)
 *
 * This file is include_once'd from the remote.d class, which ISPConfig loads on
 * EVERY remote API request (remoting_handler_base.inc.php globs
 * interface/web/*&#47;lib/classes/remote.d/*.inc.php). It must therefore stay
 * small and free of side effects: constants and functions only, no queries, no
 * output.
 *
 * ISPConfig 3.x runs on a wide PHP range and its own code is PHP 5-flavoured;
 * this file matches that style deliberately (no strict_types, no namespaces,
 * no typed properties) so the module loads on every panel its host supports.
 */

//* Direct-request guard. This file lives under the panel DOCROOT — both vhost
//* templates serve interface/web/ and deny only *.lng and dotfiles, so every
//* .php below it executes over HTTP (install/tpl/apache_ispconfig.vhost.master,
//* install/tpl/nginx_ispconfig.vhost.master). An .htaccess would not help:
//* AllowOverride is None in the mpm_itk branch and nginx ignores it entirely.
//* Legitimate inclusion always happens after interface/lib/config.inc.php has
//* run, which defines ISPC_ROOT_PATH (config.inc.php:85) — the remoting
//* handler loads remote.d classes from a bootstrapped app, and index.php
//* requires config.inc.php first. The CLI escape keeps tests/run.php working.
//* 404 rather than 403: the guard should not advertise that a file exists.
if (!defined('ISPC_ROOT_PATH') && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);
	die();
}

if (!defined('CONNECTSSO_VERSION')) {

	define('CONNECTSSO_VERSION', '1.0.0');

	/** Module directory name under interface/web — also the sys_user.modules entry. */
	define('CONNECTSSO_MODULE', 'connectsso');

	/** Path of the consumer page, reported to callers so they never hard-code it. */
	define('CONNECTSSO_ENDPOINT_PATH', '/connectsso/');

	/** Query-string parameter the consumer page reads the token from. */
	define('CONNECTSSO_TOKEN_PARAM', 'token');

	/**
	 * Token lifetime in seconds. This is a hand-off window, not a session: the
	 * only thing that happens between minting and redeeming is one HTTP
	 * redirect. Anything longer widens the window in which a token captured
	 * from a web-server access log or a browser history entry is still live.
	 * Keep <= 60.
	 */
	define('CONNECTSSO_TOKEN_TTL', 45);

	/** Entropy of the token, in bytes, before hex encoding. 32 bytes = 256 bits. */
	define('CONNECTSSO_TOKEN_BYTES', 32);

	/**
	 * How long a REDEEMED token row is kept. Used rows are retained briefly so
	 * that a replay attempt is recognisably a replay (and audited as one)
	 * rather than an unknown token, then garbage-collected.
	 */
	define('CONNECTSSO_USED_RETENTION', 3600);

	/** Redemption rate limit: attempts per source IP inside the window. */
	define('CONNECTSSO_ATTEMPT_LIMIT', 10);
	define('CONNECTSSO_ATTEMPT_WINDOW', 300);

	define('CONNECTSSO_TOKEN_TABLE', 'connectsso_token');
	define('CONNECTSSO_ATTEMPT_TABLE', 'connectsso_attempt');

	/** The one remote function this module registers. */
	define('CONNECTSSO_REMOTE_FUNCTION', 'connect_sso_token_create');
}

if (!function_exists('connectsso_token_is_wellformed')) {

	/**
	 * A token is exactly bin2hex(random_bytes(CONNECTSSO_TOKEN_BYTES)).
	 * Checked before the value is used in a query or a hash so that anything
	 * else is rejected as cheaply as possible.
	 *
	 * @param mixed $token
	 * @return bool
	 */
	function connectsso_token_is_wellformed($token) {
		if (!is_string($token)) {
			return false;
		}
		$expected = CONNECTSSO_TOKEN_BYTES * 2;

		return strlen($token) === $expected && preg_match('/^[a-f0-9]+$/', $token) === 1;
	}

	/**
	 * The value stored in the database. The clear token is NEVER stored and
	 * never logged: a read of connectsso_token must not yield a usable login.
	 *
	 * @param string $token
	 * @return string
	 */
	function connectsso_token_hash($token) {
		return hash('sha256', $token);
	}

	/**
	 * Compare two IP addresses by their binary form, so that equivalent
	 * spellings of the same address (leading zeroes, compressed IPv6, mixed
	 * case) match and nothing else does. hash_equals keeps the comparison
	 * length-independent.
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	function connectsso_ip_equals($a, $b) {
		$pa = @inet_pton((string)$a);
		$pb = @inet_pton((string)$b);
		if ($pa === false || $pb === false) {
			return false;
		}

		return hash_equals($pa, $pb);
	}

	/**
	 * Source address of the current request, as the panel sees it.
	 *
	 * Deliberately REMOTE_ADDR only: X-Forwarded-For and friends are supplied
	 * by the client, and this value gates both the rate limiter and the
	 * optional IP binding. Operators running the panel behind a reverse proxy
	 * must make the proxy set REMOTE_ADDR (mod_remoteip / realip) rather than
	 * have this module trust a header.
	 *
	 * @return string
	 */
	function connectsso_remote_addr() {
		return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
	}

	/**
	 * Delete tokens that can no longer be redeemed. Called on every mint, so
	 * the table stays small without a cron job.
	 *
	 * @param object $app
	 * @param int    $now
	 * @return void
	 */
	function connectsso_gc($app, $now) {
		$app->db->query(
			'DELETE FROM `' . CONNECTSSO_TOKEN_TABLE . '` WHERE `expires_at` < ? AND (`used_at` IS NULL OR `used_at` < ?)',
			(int)$now,
			(int)$now - CONNECTSSO_USED_RETENTION
		);
		$app->db->query(
			'DELETE FROM `' . CONNECTSSO_ATTEMPT_TABLE . '` WHERE `last_attempt` < ?',
			(int)$now - CONNECTSSO_ATTEMPT_WINDOW
		);
	}

	/**
	 * Failed redemption attempts from this address inside the window.
	 *
	 * The address is stored as md5(), matching ISPConfig's own attempts_login
	 * table. That is a bucket key and a small PII-hygiene measure, NOT a
	 * secrecy control — the IPv4 space is enumerable under any hash — so the
	 * weakness of md5 is irrelevant here and parity with core is worth more.
	 *
	 * @param object $app
	 * @param string $ip
	 * @param int    $now
	 * @return int
	 */
	function connectsso_attempts($app, $ip, $now) {
		$row = $app->db->queryOneRecord(
			'SELECT `attempts` FROM `' . CONNECTSSO_ATTEMPT_TABLE . '` WHERE `ip_hash` = ? AND `last_attempt` > ?',
			md5((string)$ip),
			(int)$now - CONNECTSSO_ATTEMPT_WINDOW
		);

		return is_array($row) ? (int)$row['attempts'] : 0;
	}

	/**
	 * Record one failed redemption. Every rejection path calls this — a bad
	 * token, an expired token, a replay, an IP-binding mismatch — so that
	 * guessing is bounded no matter which check does the rejecting.
	 *
	 * ONE atomic statement on purpose: a SELECT-then-INSERT/UPDATE pair loses
	 * counts when two rejected redemptions race (both SELECT no row, one INSERT
	 * wins the primary key, the other's INSERT fails and its attempt is never
	 * counted — an attacker parallelising guesses would be under-counted).
	 * `ip_hash` is the PRIMARY KEY (sql/install.sql), so INSERT ... ON
	 * DUPLICATE KEY UPDATE is increment-or-create in a single statement.
	 *
	 * The IF()s keep the stale-window semantics: a row whose last_attempt fell
	 * out of the window restarts at 1 instead of carrying yesterday's failures
	 * into today's budget. Assignment ORDER is load-bearing — MySQL/MariaDB
	 * evaluate ON DUPLICATE KEY assignments left to right and later ones see
	 * earlier results, so `attempts` and `first_attempt` (which must read the
	 * OLD `last_attempt`) are assigned before `last_attempt`.
	 *
	 * @param object $app
	 * @param string $ip
	 * @param int    $now
	 * @return void
	 */
	function connectsso_record_attempt($app, $ip, $now) {
		$stale = (int)$now - CONNECTSSO_ATTEMPT_WINDOW;
		$app->db->query(
			'INSERT INTO `' . CONNECTSSO_ATTEMPT_TABLE . '` (`ip_hash`, `attempts`, `first_attempt`, `last_attempt`) VALUES (?, 1, ?, ?)'
			. ' ON DUPLICATE KEY UPDATE `attempts` = IF(`last_attempt` <= ?, 1, `attempts` + 1),'
			. ' `first_attempt` = IF(`last_attempt` <= ?, ?, `first_attempt`), `last_attempt` = ?',
			md5((string)$ip),
			(int)$now,
			(int)$now,
			$stale,
			$stale,
			(int)$now,
			(int)$now
		);
	}

	/**
	 * A successful redemption clears the address's budget, mirroring how
	 * ISPConfig clears attempts_login after a successful login.
	 *
	 * @param object $app
	 * @param string $ip
	 * @return void
	 */
	function connectsso_clear_attempts($app, $ip) {
		$app->db->query(
			'DELETE FROM `' . CONNECTSSO_ATTEMPT_TABLE . '` WHERE `ip_hash` = ?',
			md5((string)$ip)
		);
	}

	/**
	 * Audit trail. Writes to sys_log (visible under Monitor > System Log) and,
	 * for events about a real login, to the panel's auth.log alongside
	 * ISPConfig's own login records (app.inc.php auth_log(), :215-221).
	 *
	 * The sys_log priority is ALWAYS 2 (LOGLEVEL_ERROR), never configurable:
	 * app.inc.php log() (interface/lib/app.inc.php:190-192) silently drops any
	 * message below $conf['log_priority'], and the installer writes
	 * log_priority = 2 on every shipped distribution
	 * (install/tpl/config.inc.php.master:145 filled from
	 * install/dist/conf/*.conf.php:39, ispconfig_log_priority = 2). Everything
	 * this module logs is a security event — a token mint, a refusal, a
	 * sign-in — and a security event logged at 0 or 1 would be recorded
	 * NOWHERE on a real install.
	 *
	 * NEVER pass a token, a token hash, or any part of either: the whole point
	 * of hashing at rest is that a log reader cannot reconstruct a login.
	 *
	 * @param object $app
	 * @param string $message
	 * @param bool   $auth_log also append to the panel auth log
	 * @return void
	 */
	function connectsso_audit($app, $message, $auth_log = false) {
		$line = '[connectsso] ' . $message . ' from ' . connectsso_remote_addr();
		$app->log($line, 2); // LOGLEVEL_ERROR — see the docblock; lower is dropped
		if ($auth_log) {
			$app->auth_log($line . ' at ' . date('Y-m-d H:i:s'));
		}
	}
}
