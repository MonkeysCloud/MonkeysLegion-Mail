<?php

use MonkeysLegion\Mail\Enums\MailDefaults;

return [
    'key' => $_ENV['RATE_LIMITER_KEY'] ?? MailDefaults::RATE_LIMITER_KEY, // Unique key for rate limiting, can be based on user ID or IP address
    'limit' => $_ENV['RATE_LIMITER_LIMIT'] ?? MailDefaults::RATE_LIMITER_LIMIT, // Maximum number of requests allowed within the time window
    'seconds' => $_ENV['RATE_LIMITER_SECONDS'] ?? MailDefaults::RATE_LIMITER_SECONDS, // Time window for rate limiting in seconds
    'storage_path' => $_ENV['RATE_LIMITER_STORAGE_PATH'] ?? MailDefaults::RATE_LIMITER_STORAGE_PATH, // Path to store rate limit data starting from the 'project_root/storage'
];
