<?php

declare(strict_types=1);

$appUrl = rtrim(getenv('APP_URL') ?: 'https://ttechcg.com', '/');

return [
    'name' => 'T&Tech Consulting Group',
    'short_name' => 'T&Tech',
    'environment' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Africa/Douala',
    'contact_email' => getenv('CONTACT_EMAIL') ?: 'info@ttechcg.com',
    'contact_from_email' => getenv('CONTACT_FROM_EMAIL') ?: 'website@ttechcg.com',
    'jumpcloud_oidc_issuer' => getenv('JUMPCLOUD_OIDC_ISSUER') ?: 'https://oauth.id.jumpcloud.com/',
    'jumpcloud_oidc_client_id' => getenv('JUMPCLOUD_OIDC_CLIENT_ID') ?: '',
    'jumpcloud_oidc_client_secret' => getenv('JUMPCLOUD_OIDC_CLIENT_SECRET') ?: '',
    'jumpcloud_oidc_redirect_uri' => getenv('JUMPCLOUD_OIDC_REDIRECT_URI') ?: $appUrl . '/pickupsheet/login/callback',
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
