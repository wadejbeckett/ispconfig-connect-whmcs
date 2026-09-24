<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Support;

use WHMCS\Module\Server\Ispconfigconnect\Api\ServerConfig;

/**
 * Turns the raw WHMCS provisioning $params array into typed objects.
 * Field names follow the WHMCS module-parameters reference
 * (https://developers.whmcs.com/provisioning-modules/module-parameters/);
 * the mapping itself is frozen in IMPLEMENTATION-NOTES §2.5/§3.1.
 */
final class Params
{
    /**
     * WHMCS has no per-server "disable TLS verification" field, so the Access
     * Hash box is overloaded: a value starting with this prefix turns
     * verification off, with the remainder as an optional CA bundle. Any
     * other non-empty value is the CA bundle (path or literal PEM).
     * Documented for operators in INSTALL.
     */
    private const INSECURE_PREFIX = 'insecure:';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $params raw WHMCS $params (server portion)
     */
    public static function serverConfig(array $params): ServerConfig
    {
        $host = trim(self::str($params, 'serverhostname'));
        if ($host === '') {
            $host = trim(self::str($params, 'serverip'));
        }

        $port = (int) self::str($params, 'serverport');
        if ($port <= 0) {
            $port = 8080;
        }

        $verifyTls = true;
        $caBundle = null;
        $accessHash = trim(self::str($params, 'serveraccesshash'));
        if (str_starts_with($accessHash, self::INSECURE_PREFIX)) {
            $verifyTls = false;
            $remainder = trim(substr($accessHash, strlen(self::INSECURE_PREFIX)));
            $caBundle = $remainder === '' ? null : $remainder;
        } elseif ($accessHash !== '') {
            $caBundle = $accessHash;
        }

        return new ServerConfig(
            host: $host,
            username: self::str($params, 'serverusername'),
            password: self::str($params, 'serverpassword'),
            port: $port,
            secure: self::str($params, 'serversecure') !== '',
            verifyTls: $verifyTls,
            caBundle: $caBundle,
        );
    }

    /**
     * @param array<string, mixed> $params raw WHMCS $params (product portion)
     */
    public static function productConfig(array $params): ProductConfig
    {
        $dnsTemplateId = trim(self::str($params, 'configoption7'));
        $phpMode = trim(self::str($params, 'configoption12'));
        $panelUrlOverride = trim(self::str($params, 'configoption14'));

        return new ProductConfig(
            clientTemplateId: (int) self::str($params, 'configoption1'),
            webServerId: (int) self::str($params, 'configoption2'),
            createWebsite: self::checkbox($params, 'configoption3'),
            createFtpUser: self::checkbox($params, 'configoption4'),
            createMailDomain: self::checkbox($params, 'configoption5'),
            createDnsZone: self::checkbox($params, 'configoption6'),
            dnsTemplateId: $dnsTemplateId === '' ? 1 : (int) $dnsTemplateId,
            ns1: trim(self::str($params, 'configoption8')),
            ns2: trim(self::str($params, 'configoption9')),
            dnsAdminEmail: trim(self::str($params, 'configoption10')),
            createDatabase: self::checkbox($params, 'configoption11'),
            phpMode: $phpMode === '' ? 'php-fpm' : $phpMode,
            suspendEntireClient: self::checkbox($params, 'configoption13'),
            panelUrlOverride: $panelUrlOverride === '' ? null : $panelUrlOverride,
        );
    }

    /**
     * @param array<string, mixed> $params raw WHMCS $params (service portion)
     */
    public static function service(array $params): ServiceParams
    {
        $details = $params['clientsdetails'] ?? null;
        if (!is_array($details)) {
            $details = [];
        }

        $name = trim(self::str($details, 'fullname'));
        if ($name === '') {
            $name = trim(
                trim(self::str($details, 'firstname')) . ' ' . trim(self::str($details, 'lastname'))
            );
        }

        $configOptions = $params['configoptions'] ?? null;

        return new ServiceParams(
            serviceId: (int) self::str($params, 'serviceid'),
            domain: self::normalizeDomain(self::str($params, 'domain')),
            username: self::str($params, 'username'),
            password: self::str($params, 'password'),
            clientDetails: [
                'name' => $name,
                'company' => trim(self::str($details, 'companyname')),
                'email' => trim(self::str($details, 'email')),
                'address1' => trim(self::str($details, 'address1')),
                'city' => trim(self::str($details, 'city')),
                'state' => trim(self::str($details, 'state')),
                'postcode' => trim(self::str($details, 'postcode')),
                'country' => trim(self::str($details, 'country')),
                'phone' => trim(self::str($details, 'phonenumber')),
            ],
            whmcsClientId: (int) self::str($params, 'userid'),
            serverIp: trim(self::str($params, 'serverip')),
            language: trim(self::str($details, 'language')),
            configOptions: is_array($configOptions) ? $configOptions : [],
        );
    }

    /**
     * @param array<string, mixed> $params raw WHMCS $params; requires the
     *     Eloquent service model under 'model'
     */
    public static function serviceProperties(array $params): ServiceProperties
    {
        $model = $params['model'] ?? null;
        // ?? goes through __isset on the Eloquent model, so the magic
        // serviceProperties accessor resolves without a PHP warning either way.
        $properties = is_object($model) ? ($model->serviceProperties ?? null) : null;
        if (!is_object($properties)) {
            throw new \InvalidArgumentException(
                "\$params['model']->serviceProperties is required to read or store "
                . 'service properties; inject a ServiceProperties double directly in unit tests.'
            );
        }

        return new ServiceProperties($properties);
    }

    /**
     * Scalars only; arrays/objects in an unexpected slot read as ''.
     *
     * @param array<string, mixed> $params
     */
    private static function str(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Normalise a domain for ISPConfig: trim, lower-case, and IDN-encode to
     * ASCII punycode. ISPConfig's domain regex rejects upper-case and non-ASCII
     * input, so an uppercase or internationalised domain would otherwise fail
     * the web/DNS stage and roll the whole account back. When the intl
     * extension is absent, a non-ASCII domain is left lower-cased (ISPConfig
     * will still reject it, but ASCII domains provision correctly).
     */
    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }

        if (preg_match('/[^\x00-\x7f]/', $domain) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        return $domain;
    }

    /**
     * WHMCS yesno options arrive as 'on' (checked) or '' (unchecked).
     *
     * @param array<string, mixed> $params
     */
    private static function checkbox(array $params, string $key): bool
    {
        return self::str($params, $key) === 'on';
    }
}
