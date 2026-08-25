<?php

/*
 * ispconfig-sso — panel single sign-on for ISPConfig (companion module).
 * Copyright (c) 2026 Wade Beckett.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version. See ../../../../../../LICENSE.
 *
 * -----------------------------------------------------------------------------
 * Remote API extension: mint a short-lived, single-use panel-login token.
 *
 * HOW ISPCONFIG FINDS THIS FILE (verified, 3.3.1p1):
 *   interface/lib/classes/remoting_handler_base.inc.php:47 globs
 *     realpath(__DIR__) . '/../../web/*&#47;lib/classes/remote.d/*.inc.php'
 *   include_once's each match, and registers the class named
 *     'remoting_' . basename-without-.inc.php
 *   mapping EVERY public method of that class into the handler's method map
 *   (lines 58-74). No core file is modified to add a remote function; this is
 *   the same mechanism the ten built-in remote.d classes use.
 *
 *   Consequence worth knowing: get_class_methods() also sees the PUBLIC methods
 *   inherited from `remoting` — login, logout, get_function_list, server_get,
 *   server_get_all. The handler constructor re-pins the first three to the
 *   `remoting` instance immediately afterwards (lines 50-52); server_get and
 *   server_get_all end up dispatched to THIS class instead. That is harmless —
 *   they are inherited verbatim, not overridden — and it is what already
 *   happens between the built-in classes on a stock install (remoting_sites,
 *   loaded last, owns them today). It is also why this class declares exactly
 *   ONE public method of its own.
 *
 * WHY THIS MODULE EXISTS: ISPConfig core has no one-time-login API.
 * client_login_get() only validates credentials, and login($u, $p, true) mints
 * an API session, not a panel session. Without something like this, a billing
 * system's "Log in to panel" button can only be implemented by storing and
 * replaying the customer's password — which is exactly what the abandoned
 * WHMCS modules did, and exactly what this module exists to make unnecessary.
 *
 * The clear token is returned ONCE, to the caller, and is never stored, logged
 * or re-derivable: the database holds only its SHA-256.
 */

//* Direct-request guard: this file sits under the panel docroot (both vhost
//* templates serve interface/web/ and deny only *.lng and dotfiles), and
//* requesting it over HTTP would otherwise fatal on `extends remoting` with
//* the class undefined. Legitimate loading — the remoting handler's glob, or
//* the tests — always has ISPC_ROOT_PATH (config.inc.php:85) or runs on the
//* CLI. Mirrors the guard in connectsso_lib.inc.php; see the rationale there.
if (!defined('ISPC_ROOT_PATH') && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);
	die();
}

require_once realpath(__DIR__ . '/../../connectsso_lib.inc.php');

class remoting_connectsso extends remoting {

	/**
	 * Mint a single-use panel-login token for one ISPConfig client.
	 *
	 * @param string $session_id remote API session (remoting::login)
	 * @param int    $client_id  client.client_id to log in as
	 * @param string $bind_ip    optional: the address the token may be redeemed
	 *                           FROM. This is the END USER's browser address,
	 *                           not the API caller's — the caller mints, the
	 *                           browser redeems. '' leaves the token unbound.
	 * @return array{token: string, path: string, expires_in: int, client_id: int, username: string}
	 * @throws SoapFault
	 */
	public function connect_sso_token_create($session_id, $client_id, $bind_ip = '') {
		global $app;

		if (!$this->checkPerm($session_id, CONNECTSSO_REMOTE_FUNCTION)) {
			// Verbatim core wording (remoting.inc.php) so existing clients
			// classify it as a permission problem, not an unknown fault.
			throw new SoapFault('permission_denied', 'You do not have the permissions to access this function.');
		}

		//* checkPerm() alone does NOT authorise this function. For a
		//* client_login session — a CUSTOMER who called login($u, $p, true),
		//* which any client with can_use_api = 'y' may do — checkPerm()
		//* returns true unconditionally ("permissions are checked at an other
		//* place", remoting.inc.php:542-547), and such sessions are stored
		//* with client_login = 1 and remote_functions = ''
		//* (remoting.inc.php:99-156). Trusting checkPerm() here would let any
		//* API-enabled customer mint a panel login for any other non-admin
		//* client: cross-tenant panel takeover. The billing system
		//* authenticates as a REMOTE USER (client_login = 0), never as a
		//* client, so client sessions are refused outright — with core's
		//* verbatim wording, because WHY is nobody's business but the log's.
		//* Fail closed: only a session that PROVABLY carries client_login = 0
		//* (the column is NOT NULL in remote_session) may proceed — a missing
		//* key must refuse, not default to "remote user".
		$session = $this->getSession($session_id);
		if (!is_array($session) || !isset($session['client_login']) || (int)$session['client_login'] !== 0) {
			connectsso_audit(
				$app,
				'refused to mint a token for client ' . intval($client_id)
				. ': the API session is a client session, not a remote user',
				true
			);
			throw new SoapFault('permission_denied', 'You do not have the permissions to access this function.');
		}

		$client_id = intval($client_id);
		if ($client_id < 1) {
			throw new SoapFault('invalid_client_id', 'The client id must be an integer greater than zero.');
		}

		$bind_ip = trim((string)$bind_ip);
		if ($bind_ip !== '' && !filter_var($bind_ip, FILTER_VALIDATE_IP)) {
			throw new SoapFault('invalid_bind_ip', 'The bind IP address is invalid.');
		}

		//* The panel user this client logs in as. sys_user.client_id is set by
		//* remoting_lib::ispconfig_sysuser_add() when the client is created.
		$user = $app->db->queryOneRecord(
			'SELECT `userid`, `username`, `active`, `typ` FROM `sys_user` WHERE `client_id` = ? ORDER BY `userid` ASC',
			$client_id
		);
		if (!is_array($user)) {
			throw new SoapFault('sso_no_user', 'There is no panel user for this client id.');
		}

		//* Never mint for an administrator. Tokens bypass password and IP
		//* checks by design; an admin token is a full panel takeover, and no
		//* legitimate caller of this function needs one.
		if ($user['typ'] === 'admin') {
			throw new SoapFault('sso_refused', 'Single sign-on is not available for administrator accounts.');
		}
		if ((int)$user['active'] !== 1) {
			throw new SoapFault('sso_user_inactive', 'The panel user for this client is not active.');
		}

		//* A locked or cancelled client is a SUSPENDED customer. Handing out a
		//* login that ignores that would quietly undo the suspension the
		//* billing system just applied.
		$client = $app->db->queryOneRecord(
			'SELECT `locked`, `canceled` FROM `client` WHERE `client_id` = ?',
			$client_id
		);
		if (is_array($client) && ($client['locked'] === 'y' || $client['canceled'] === 'y')) {
			throw new SoapFault('sso_client_locked', 'The client account is locked; single sign-on is refused.');
		}

		$now = time();
		connectsso_gc($app, $now);

		//* One live token per client: minting a new one invalidates any unused
		//* predecessor. That caps how many redeemable tokens a caller can
		//* accumulate (mint-side rate limit) and means an abandoned SSO click
		//* cannot be redeemed later from a browser history entry.
		$app->db->query(
			'DELETE FROM `' . CONNECTSSO_TOKEN_TABLE . '` WHERE `client_id` = ? AND `used_at` IS NULL',
			$client_id
		);

		$token = bin2hex(random_bytes(CONNECTSSO_TOKEN_BYTES));

		//* $session was fetched by the client_login gate above.
		$created_by = isset($session['remote_userid']) ? (int)$session['remote_userid'] : 0;

		$app->db->query(
			'INSERT INTO `' . CONNECTSSO_TOKEN_TABLE . '`'
			. ' (`token_hash`, `sys_userid`, `client_id`, `bind_ip`, `created_at`, `expires_at`, `created_by`)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?)',
			connectsso_token_hash($token),
			(int)$user['userid'],
			$client_id,
			$bind_ip,
			$now,
			$now + CONNECTSSO_TOKEN_TTL,
			$created_by
		);

		//* auth_log = true: issuing a token CREATES a login credential, so the
		//* record belongs in auth.log beside ISPConfig's own login records —
		//* sys_log alone is browsable panel state, not a durable auth trail.
		connectsso_audit(
			$app,
			'minted a login token for client ' . $client_id . ' (user ' . $user['username'] . ')'
			. ($bind_ip === '' ? ', unbound' : ', bound to ' . $bind_ip)
			. ' for remote user ' . $created_by,
			true
		);

		return array(
			'token'      => $token,
			'path'       => CONNECTSSO_ENDPOINT_PATH,
			'expires_in' => CONNECTSSO_TOKEN_TTL,
			'client_id'  => $client_id,
			'username'   => (string)$user['username'],
		);
	}
}
