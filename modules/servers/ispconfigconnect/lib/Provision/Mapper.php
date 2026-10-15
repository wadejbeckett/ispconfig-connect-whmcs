<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Provision;

use WHMCS\Module\Server\Ispconfigconnect\Support\ProductConfig;
use WHMCS\Module\Server\Ispconfigconnect\Support\ServiceParams;

/**
 * Pure WHMCS-data → ISPConfig-call-params mapping. No I/O, no session, no
 * WHMCS function calls — every method is a deterministic function of its
 * arguments. Field names and required-field sets are taken from the
 * ISPConfig 3.3.1p1 form definitions (interface/web/<module>/form/*.tform.php);
 * remote calls validate MISSING fields as '' (tform_base.inc.php runs every
 * field's validators with '' when the key is absent), so any NOTEMPTY or
 * no-allowempty REGEX field must be sent explicitly.
 */
final class Mapper
{
    /**
     * The configurable-option prefix that binds an option to a same-named
     * ISPConfig addon client template (design §3.4).
     */
    private const ADDON_OPTION_PREFIX = 'ISPConfig ';

    /**
     * WHMCS client language names → ISPConfig interface language codes.
     * Codes limited to what 3.3.1p1 actually ships in interface/lib/lang/
     * (ar bg br ca cn cz de dk el en es fi fr hr hu id it ja nl pl pt ro
     * ru se sk tr); anything unmappable falls back to 'en' because
     * client.tform.php marks language NOTEMPTY.
     */
    private const LANGUAGE_MAP = [
        'arabic' => 'ar',
        'bulgarian' => 'bg',
        'catalan' => 'ca',
        'chinese' => 'cn',
        'croatian' => 'hr',
        'czech' => 'cz',
        'danish' => 'dk',
        'dutch' => 'nl',
        'english' => 'en',
        'finnish' => 'fi',
        'french' => 'fr',
        'german' => 'de',
        'greek' => 'el',
        'hungarian' => 'hu',
        'indonesian' => 'id',
        'italian' => 'it',
        'japanese' => 'ja',
        'polish' => 'pl',
        'portuguese' => 'pt',
        'portuguese-br' => 'br',
        'portuguese-pt' => 'pt',
        'romanian' => 'ro',
        'russian' => 'ru',
        'slovak' => 'sk',
        'spanish' => 'es',
        'swedish' => 'se',
        'turkish' => 'tr',
    ];

    /** Codes shipped by ISPConfig 3.3.1p1 (interface/lib/lang/*.lng). */
    private const SUPPORTED_LANGUAGE_CODES = [
        'ar', 'bg', 'br', 'ca', 'cn', 'cz', 'de', 'dk', 'el', 'en', 'es',
        'fi', 'fr', 'hr', 'hu', 'id', 'it', 'ja', 'nl', 'pl', 'pt', 'ro',
        'ru', 'se', 'sk', 'tr',
    ];

    /**
     * Params for client_add. Field names per client.tform.php; NOTEMPTY
     * there: contact_name, username, language, email.
     *
     * @return array<string, int|string>
     */
    public static function clientAddParams(ProductConfig $product, ServiceParams $service): array
    {
        $contact = $service->clientDetails;

        return [
            'company_name' => $contact['company'] ?? '',
            'contact_name' => $contact['name'] ?? '',
            'username' => $service->username,
            'password' => $service->password,
            'language' => self::ispconfigLanguage($service->language),
            // client.tform.php validates usertheme with REGEX
            // /^[a-zA-Z0-9_-]{1,32}$/ and no allowempty — a missing field
            // validates as '' and fails (ISPConfig bug #6990), so the theme
            // is ALWAYS sent explicitly.
            'usertheme' => 'default',
            // Both NOTEMPTY with no server-side default (client.tform.php
            // :1135ff, :1272ff; live-verified failure web_php_options_notempty
            // / ssh_chroot_notempty on 3.3.1p1): the client's ALLOWED php
            // modes and chroot options. Sent permissive - the client template
            // governs actual limits.
            'web_php_options' => 'no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm',
            'ssh_chroot' => 'no,jailkit',
            'street' => $contact['address1'] ?? '',
            'zip' => $contact['postcode'] ?? '',
            'city' => $contact['city'] ?? '',
            'state' => $contact['state'] ?? '',
            // WHMCS stores the ISO-3166 two-letter code, which is exactly
            // what client.tform.php's country datasource keys on (iso).
            'country' => $contact['country'] ?? '',
            'telephone' => $contact['phone'] ?? '',
            'email' => $contact['email'] ?? '',
            'template_master' => $product->clientTemplateId,
            'template_additional' => '',
            'default_webserver' => $product->webServerId,
            'default_mailserver' => $product->webServerId,
            'default_dnsserver' => $product->webServerId,
            'default_dbserver' => $product->webServerId,
            'notes' => 'Managed by WHMCS service #' . $service->serviceId,
        ];
    }

    /**
     * Params for sites_web_domain_add. web_vhost_domain.tform.php marks
     * traffic_quota, hd_quota and allow_override NOTEMPTY, so all three are
     * sent explicitly; remoting fills document_root/system_user/system_group
     * server-side (remote.d/sites.inc.php).
     *
     * @return array<string, int|string>
     */
    public static function webDomainAddParams(ProductConfig $product, ServiceParams $service): array
    {
        return [
            'server_id' => $product->webServerId,
            'ip_address' => '*',
            'domain' => $service->domain,
            'type' => 'vhost',
            'parent_domain_id' => 0,
            'subdomain' => 'www',
            'php' => $product->phpMode,
            'hd_quota' => -1,
            'traffic_quota' => -1,
            'allow_override' => 'All',
            'active' => 'y',
            'errordocs' => 1,
            // web_vhost_domain.tform.php REGEX validators WITHOUT allowempty:
            // pm_process_idle_timeout /^([1-9][0-9]{0,10})$/, pm_max_requests
            // /^([0-9]{1,11})$/, http_port + https_port /^([0-9]{1,5})$/.
            // A missing field validates as '' and fails, so the remote API
            // effectively REQUIRES all four; values are the stock example's
            // (remoting_client/examples/sites_web_domain_add.php).
            'pm_process_idle_timeout' => 10,
            'pm_max_requests' => 0,
            'http_port' => 80,
            'https_port' => 443,
        ];
    }

    /**
     * Params for sites_ftp_user_add, built from the record returned by
     * sites_web_domain_get: uid/gid/dir are NOTEMPTY in ftp_user.tform.php
     * and must carry the web's system_user/system_group/document_root.
     * The username is the service username verbatim — ISPConfig's
     * client-prefix convention is interface-only and does not apply over
     * the remote API.
     *
     * @param array<string, mixed> $webDomainRecord record from sites_web_domain_get
     * @return array<string, int|string>
     */
    public static function ftpUserAddParams(ServiceParams $service, array $webDomainRecord): array
    {
        return [
            'server_id' => (int) $webDomainRecord['server_id'],
            'parent_domain_id' => (int) $webDomainRecord['domain_id'],
            'username' => $service->username,
            'password' => $service->password,
            'uid' => (string) $webDomainRecord['system_user'],
            'gid' => (string) $webDomainRecord['system_group'],
            'dir' => (string) $webDomainRecord['document_root'],
            // quota_size is NOTEMPTY in ftp_user.tform.php; -1 = unlimited.
            'quota_size' => -1,
            'active' => 'y',
        ];
    }

    /**
     * Params for mail_domain_add. Only domain is NOTEMPTY in
     * mail_domain.tform.php, but dkim_selector carries a REGEX validator
     * /^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/ without allowempty — the same
     * missing-field failure mode as usertheme — so the form default
     * ('default') is sent explicitly.
     *
     * @return array<string, int|string>
     */
    public static function mailDomainAddParams(ProductConfig $product, ServiceParams $service): array
    {
        return [
            'server_id' => $product->webServerId,
            'domain' => $service->domain,
            'dkim_selector' => 'default',
            'active' => 'y',
        ];
    }

    /**
     * Deterministic database + database-user name, shared by both:
     * 'c<ispconfigClientId>_<alnum(sld)>' truncated to 16 chars total (the
     * historical MySQL user-name limit). The client-id prefix is never
     * truncated, so names stay collision-free across clients; within a
     * client there is exactly one database per service in the MVP.
     *
     * @return array{user: string, name: string}
     */
    public static function databaseNames(ServiceParams $service, int $clientId): array
    {
        $sld = explode('.', $service->domain, 2)[0];
        $sld = (string) preg_replace('/[^a-z0-9]/', '', strtolower($sld));

        $prefix = 'c' . $clientId . '_';
        $room = max(0, 16 - strlen($prefix));
        $name = $prefix . substr($sld, 0, $room);

        return ['user' => $name, 'name' => $name];
    }

    /**
     * Arguments for dns_templatezone_add, keyed by that function's PHP
     * parameter names (interface/lib/classes/remote.d/dns.inc.php) — the
     * JSON handler maps body keys to parameters by reflection. session_id
     * and client_id are supplied by the caller.
     *
     * @return array<string, int|string>
     */
    public static function dnsTemplateArgs(ProductConfig $product, ServiceParams $service): array
    {
        $email = trim($product->dnsAdminEmail);
        if ($email === '') {
            $email = 'admin@' . $service->domain;
        }

        return [
            'template_id' => $product->dnsTemplateId,
            'domain' => $service->domain,
            'ip' => $service->serverIp,
            'ns1' => $product->ns1,
            'ns2' => $product->ns2,
            'email' => $email,
        ];
    }

    /**
     * Resolves enabled configurable options named 'ISPConfig <Name>' to
     * addon (template_type 'a') client-template ids by exact name match
     * against client_templates_get_all rows (design §3.4). Used by both
     * create and changePackage. An enabled option with no matching addon
     * template is a product misconfiguration and throws — silently
     * skipping it would provision less than the customer bought.
     *
     * @param list<array<string, mixed>> $clientTemplates client_templates_get_all rows
     * @return list<int> template ids, in option order
     */
    public static function addonTemplateIds(ServiceParams $service, array $clientTemplates): array
    {
        $addonsByName = [];
        foreach ($clientTemplates as $row) {
            if (($row['template_type'] ?? '') === 'a') {
                $addonsByName[(string) $row['template_name']] = (int) $row['template_id'];
            }
        }

        $ids = [];
        foreach ($service->configOptions as $optionName => $value) {
            $optionName = (string) $optionName;
            if (!str_starts_with($optionName, self::ADDON_OPTION_PREFIX)) {
                continue;
            }
            // WHMCS checkbox/quantity options arrive as 0/''/'0' when off.
            if (in_array((string) $value, ['', '0'], true)) {
                continue;
            }

            $templateName = substr($optionName, strlen(self::ADDON_OPTION_PREFIX));
            if (!isset($addonsByName[$templateName])) {
                throw new \InvalidArgumentException(sprintf(
                    'Configurable option "%s" names no ISPConfig addon template: '
                    . 'no template_type "a" row called "%s" exists.',
                    $optionName,
                    $templateName
                ));
            }

            $ids[] = $addonsByName[$templateName];
        }

        return $ids;
    }

    /**
     * WHMCS clientsdetails.language (a lowercase language name such as
     * 'english', occasionally already a code) → ISPConfig two-letter
     * language code, defaulting to 'en' (language is NOTEMPTY in
     * client.tform.php).
     */
    private static function ispconfigLanguage(string $whmcsLanguage): string
    {
        $normalized = strtolower(trim($whmcsLanguage));

        if (isset(self::LANGUAGE_MAP[$normalized])) {
            return self::LANGUAGE_MAP[$normalized];
        }

        if (in_array($normalized, self::SUPPORTED_LANGUAGE_CODES, true)) {
            return $normalized;
        }

        return 'en';
    }
}
