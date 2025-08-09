<?php

declare(strict_types=1);

/**
 * @param mixed $value
 * @param string $default
 * @return string
 */
function safeString(mixed $value, string $default = ''): string
{
    if (is_scalar($value)) {
        return (string) $value;
    }
    return $default;
}

$paths = [
    dirname(__DIR__, 4) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 1) . '/vendor/autoload.php',
];
foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use Composer\InstalledVersions;

if (!function_exists('dd')) {
    /**
     * Dump variables and terminate the script
     * @param mixed ...$args Variables to dump
     */
    function dd(...$args): void
    {
        foreach ($args as $arg) {
            if (is_string($arg)) {
                echo htmlspecialchars($arg, ENT_QUOTES, 'UTF-8');
            } elseif (is_scalar($arg) || (is_object($arg) && method_exists($arg, '__toString'))) {
                echo htmlspecialchars((string)$arg, ENT_QUOTES, 'UTF-8');
            } else {
                echo '<pre>' . print_r($arg, true) . '</pre>';
            }
        }
        exit(1);
    }
}

if (!defined('WORKING_DIRECTORY')) {
    try {
        $all = InstalledVersions::getAllRawData();
        $installPath = $all[1]['root']['install_path'] ?? null;

        if ($installPath && is_dir($installPath)) {
            $realInstallPath = realpath($installPath);
            $path = safeString($realInstallPath, '');
            if ($path !== '') {
                define('WORKING_DIRECTORY', $path);
            } else {
                $cwd = getcwd();
                define('WORKING_DIRECTORY', safeString($cwd, '/'));
            }
        } else {
            $cwd = getcwd();
            $realCwd = $cwd !== false ? realpath($cwd) : false;
            $path = safeString($realCwd, '');
            if ($path !== '') {
                define('WORKING_DIRECTORY', $path);
            } else {
                define('WORKING_DIRECTORY', '/');
            }
        }
    } catch (\Throwable $e) {
        $default = getcwd();
        $realDefault = $default !== false ? realpath($default) : false;
        $path = safeString($realDefault, '');
        if ($path !== '') {
            define('WORKING_DIRECTORY', $path);
        } else {
            define('WORKING_DIRECTORY', '/');
        }
    }
}

/**
 * Normalize an attachment path or array.
 *
 * @param array<string, string|null>|string $attachment
 * @param string|null $baseDirectory
 * @param bool $forCurl If true, returns data suitable for CURLFile; else for MIME encoding.
 * @return array{
 *     path: string,
 *     filename: string,
 *     mime_type: string,
 *     is_url: bool,
 *     full_path?: string,
 *     base64?: string,
 *     boundary_encoded?: string
 * }
 * @throws RuntimeException
 */
function normalizeAttachment(array|string $attachment, ?string $baseDirectory = null, bool $forCurl = false): array
{
    if (is_array($attachment)) {
        $path = $attachment['path'] ?? null;
        $filename = $attachment['name'] ?? null;
        $mimeType = $attachment['mime_type'] ?? null;
    } elseif (is_string($attachment)) {
        $path = $attachment;
        $filename = null;
        $mimeType = null;
    } else {
        throw new \RuntimeException('Invalid attachment format');
    }

    if (!$path) {
        throw new \RuntimeException('Attachment path is missing');
    }

    $isUrl = preg_match('#^https?://#i', $path) === 1;

    if ($isUrl) {
        $fullPath = $path;
        $fileData = @file_get_contents($path);
    } else {
        $baseDir = rtrim($baseDirectory ?? WORKING_DIRECTORY, DIRECTORY_SEPARATOR);
        $relativePath = ltrim(trim($path), DIRECTORY_SEPARATOR);
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . $relativePath;

        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            throw new \RuntimeException("Attachment file not found or unreadable: $fullPath");
        }

        $fileData = @file_get_contents($fullPath);
    }

    if ($fileData === false) {
        throw new \RuntimeException("Failed to read attachment data from: $fullPath");
    }

    $filename ??= basename($path);
    $mimeType ??= $isUrl ? 'application/octet-stream' : (mime_content_type($fullPath) ?: 'application/octet-stream');

    $result = [
        'path' => $path,
        'filename' => $filename,
        'mime_type' => $mimeType,
        'is_url' => $isUrl,
    ];

    if (!$isUrl) {
        $result['full_path'] = $fullPath;
    }

    if ($forCurl) {
        return $result;
    }

    $boundaryEncoded =
        "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n" .
        "Content-Disposition: attachment; filename=\"{$filename}\"\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        chunk_split(base64_encode($fileData));

    $result['base64'] = base64_encode($fileData);
    $result['boundary_encoded'] = $boundaryEncoded;

    return $result;
}
