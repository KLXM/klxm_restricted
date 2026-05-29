<?php

use LDAP\Connection;

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (\PHP_VERSION_ID >= 80300) {
    return;
}

if (!function_exists('ldap_exop_sync') && function_exists('ldap_exop')) {
    function ldap_exop_sync(Connection $ldap, string $requestOid, ?string $requestData = null, ?array $controls = null, &$responseData = null, &$responseOid = null): bool { return ldap_exop($ldap, $requestOid, $requestData, $controls, $responseData, $responseOid); }
}

if (!function_exists('ldap_connect_wallet') && function_exists('ldap_connect')) {
    function ldap_connect_wallet(?string $uri, string $wallet, #[SensitiveParameter] string $password, int $authMode = \GSLC_SSL_NO_AUTH): Connection|false { return ldap_connect($uri, $wallet, $password, $authMode); }
}
