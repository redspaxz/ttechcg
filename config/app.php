<?php

declare(strict_types=1);

return [
    'name' => 'T&Tech Consulting Group',
    'short_name' => 'T&Tech',
    'environment' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Africa/Lagos',
    'contact_email' => getenv('CONTACT_EMAIL') ?: '',
    'contact_from_email' => getenv('CONTACT_FROM_EMAIL') ?: 'website@ttechcg.com',
];
