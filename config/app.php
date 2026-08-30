<?php

declare(strict_types=1);

$localLoginSetting = getenv('PICKUPSHEET_LOCAL_LOGIN_ENABLED');
$localLoginEnabled = $localLoginSetting === false || trim($localLoginSetting) === ''
    ? true
    : filter_var($localLoginSetting, FILTER_VALIDATE_BOOL);

return [
    'name' => 'T&Tech Consulting Group',
    'short_name' => 'T&Tech',
    'environment' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Africa/Douala',
    'contact_email' => getenv('CONTACT_EMAIL') ?: 'info@ttechcg.com',
    'contact_from_email' => getenv('CONTACT_FROM_EMAIL') ?: 'website@ttechcg.com',
    'local_login_enabled' => $localLoginEnabled,
    'locations' => [
        [
            'city' => 'Bamenda',
            'address' => 'Commercial Avenue',
            'region' => 'North-West Region',
            'country' => 'Cameroon',
        ],
        [
            'city' => 'Douala',
            'address' => 'Bonapriso',
            'region' => 'Littoral Region',
            'country' => 'Cameroon',
        ],
    ],
];
