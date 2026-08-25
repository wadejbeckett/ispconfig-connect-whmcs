<?php

/*
 * ispconfig-sso — panel single sign-on for ISPConfig (companion module).
 * Copyright (c) 2026 Wade Beckett. GPLv3 — see ../../../../LICENSE.
 *
 * Makes this module's remote function grantable in the panel UI.
 *
 * System > Remote Users builds its "Functions" checkbox list by including
 * <module>/lib/remote.conf.php for every module in the LOGGED-IN ADMIN's
 * sys_user.modules list (verified: interface/web/admin/form/remote_user.tform.php
 * lines 36-45) and appending to $function_list. The array KEY is the
 * comma-separated list of function names one checkbox grants; the VALUE is its
 * label, passed through $app->tform->lng(), which returns unknown strings
 * verbatim (tform.inc.php lng()).
 *
 * This is why install.sh adds 'connectsso' to every admin's modules column:
 * without it this file is never included, the checkbox never renders, and —
 * worse — saving the Remote Users form would drop the grant, because
 * CHECKBOXARRAY fields are rebuilt from the POSTed checkboxes on save
 * (tform_base.inc.php:832).
 */

//* Direct-request guard: this file sits under the panel docroot. Legitimate
//* inclusion (the Remote Users form) always runs after config.inc.php defined
//* ISPC_ROOT_PATH; see connectsso_lib.inc.php for the full rationale.
if (!defined('ISPC_ROOT_PATH') && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);
	die();
}

$function_list['connect_sso_token_create'] = 'ISPConfig Connect: panel single sign-on (SSO token)';
