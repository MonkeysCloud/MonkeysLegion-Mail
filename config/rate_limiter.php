<?php

return [
    'key' => $_ENV['RATE_LIMITER_KEY'] ?? 'mail', // Unique key for rate limiting, can be based on user ID or IP address
    'limit' => $_ENV['RATE_LIMITER_LIMIT'] ?? 100, // Maximum number of requests allowed within the time window
    'seconds' => $_ENV['RATE_LIMITER_SECONDS'] ?? 60, // Time window for rate limiting in seconds
    'storage_path' => $_ENV['RATE_LIMITER_STORAGE_PATH'] ?? '/mail', // Path to store rate limit data starting from the 'project_root/storage'
];
