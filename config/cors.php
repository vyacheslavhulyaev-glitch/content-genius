<?php

return [
    'paths' => ['sanctum/csrf-cookie', 'login', 'logout', 'api/user', 'api/contents', 'api/contents/*', 'api/contents/*/generate', 'api/contents/*/regenerate', 'api/admin/dashboard'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
