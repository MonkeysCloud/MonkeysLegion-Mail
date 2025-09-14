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

/**
 * Normalize an attachment path or array.
 *
 * @param array<string, mixed>|string $attachment
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
        $path = is_string($attachment['path']) ? $attachment['path'] : '';
        $filename = isset($attachment['name']) && is_scalar($attachment['name']) ? (string)$attachment['name'] : null;
        $mimeType = isset($attachment['mime_type']) && is_string($attachment['mime_type']) ? $attachment['mime_type'] : null;
    } else {
        // string case
        $path = $attachment;
        $filename = null;
        $mimeType = null;
    }

    if ($path === '') {
        throw new \RuntimeException('Attachment path is missing');
    }

    $isUrl = preg_match('#^https?://#i', $path) === 1;

    if ($isUrl) {
        $fullPath = $path;
        $fileData = @file_get_contents($path);
    } else {
        $baseDir = rtrim($baseDirectory ?? base_path(), DIRECTORY_SEPARATOR);
        $relativePath = ltrim($path, DIRECTORY_SEPARATOR);
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_file($fullPath) || !is_readable($fullPath)) {
            throw new \RuntimeException("Attachment file not found or unreadable: {$fullPath}");
        }

        $fileData = @file_get_contents($fullPath);
    }

    if ($fileData === false) {
        throw new \RuntimeException("Failed to read attachment data from: {$path}");
    }

    $filename = $filename !== null && $filename !== '' ? $filename : basename($path);
    $mimeType = $mimeType !== null && $mimeType !== ''
        ? $mimeType
        : ($isUrl ? 'application/octet-stream' : (mime_content_type($fullPath) ?: 'application/octet-stream'));

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

    $result['base64'] = base64_encode($fileData);
    $result['boundary_encoded'] =
        "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n" .
        "Content-Disposition: attachment; filename=\"{$filename}\"\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        chunk_split($result['base64']);

    return $result;
}
