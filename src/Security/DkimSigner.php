<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Security;

use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SendmailTransport;
use RuntimeException;

class DkimSigner
{
    private string $privateKey;
    private string $selector;
    private string $domain;

    public function __construct(string $privateKey, string $selector, string $domain)
    {
        $this->privateKey = $privateKey;
        $this->selector = $selector;
        $this->domain = $domain;
    }

    /**
     * Generate a new DKIM key pair
     * @param int $bits Number of bits for the key (default 2048)
     * @return array{private: string, public: string} Contains the private and public keys
     * @throws RuntimeException If key generation fails
     */
    public static function generateKeys($bits = 2048): array
    {
        $opensslConfig = realpath(__DIR__ . '/../../config/openssl.cnf');

        if (!$opensslConfig || !is_readable($opensslConfig)) {
            throw new RuntimeException("OpenSSL config file not found or not readable");
        }

        // Ensure temp directory is writable
        $tempDir = sys_get_temp_dir();
        if (!is_writable($tempDir)) {
            throw new RuntimeException("Temporary directory is not writable: $tempDir");
        }

        $config = [
            "private_key_bits" => $bits,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
            "config" => $opensslConfig,
            // "encrypt_key" => false,
        ];

        // Generate the key pair
        $keyResource = openssl_pkey_new($config);

        if (!$keyResource) {
            $error = openssl_error_string();
            throw new RuntimeException("Failed to generate private key: $error");
        }

        // Export private key with the SAME config
        $privateKey = '';
        $exportResult = openssl_pkey_export($keyResource, $privateKey, null, $config);

        if (!is_string($privateKey) || $privateKey === '') {
            throw new RuntimeException("Exported private key is not a valid string");
        }

        if (!$exportResult) {
            $error = openssl_error_string();
            throw new RuntimeException("Failed to export private key: $error");
        }

        // Extract public key
        $publicKeyDetails = openssl_pkey_get_details($keyResource);
        if (!$publicKeyDetails) {
            $error = openssl_error_string();
            throw new RuntimeException("Failed to get public key details: $error");
        }

        if (!isset($publicKeyDetails['key']) || !is_string($publicKeyDetails['key']) || $publicKeyDetails['key'] === '') {
            throw new RuntimeException("Public key is not a valid string");
        }

        return [
            'private' => $privateKey,
            'public' => $publicKeyDetails['key']
        ];
    }

    /**
     * Sign the email headers and body using DKIM
     * @param array<string, string> $headers Associative array of email headers
     * @param string $body The email body to sign
     * @return string The DKIM-Signature header value
     * @throws RuntimeException If signing fails
     */
    public function sign(array $headers, string $body): string
    {
        // Canonicalize the body according to simple canonicalization
        $canonicalBody = $this->canonicalizeBody($body);

        // Get only the headers we want to sign in the correct order
        $headersToSign = ['From', 'To', 'Subject', 'Date', 'Message-ID'];
        $canonicalHeaders = $this->canonicalizeHeadersSimple($headers, $headersToSign);

        $bodyHash = base64_encode(hash('sha256', $canonicalBody, true));

        // Create DKIM header base WITHOUT the signature value
        $dkimHeaderBase = "v=1; a=rsa-sha256; c=relaxed/relaxed; d={$this->domain}; s={$this->selector}; ";
        $dkimHeaderBase .= "h=" . strtolower(implode(':', $headersToSign)) . "; bh={$bodyHash}; b=";

        // Create the string to sign (headers + dkim header without signature)
        $stringToSign = $canonicalHeaders . "dkim-signature:" . $dkimHeaderBase;

        // Clean up the private key
        $privateKey = $this->cleanPrivateKey($this->privateKey);

        $signature = '';
        $signResult = openssl_sign($stringToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$signResult || !is_string($signature) || $signature === '') {
            throw new RuntimeException("Failed to sign DKIM headers: " . openssl_error_string());
        }

        $b64Signature = base64_encode($signature);
        $finalSignature = 'DKIM-Signature: ' . $dkimHeaderBase . $b64Signature;

        return $finalSignature;
    }

    /**
     * Canonicalize headers according to simple canonicalization
     * Preserves header name case and exact formatting
     * @param array<string, string> $headers Associative array of headers
     * @param array<string> $headersToSign Headers to include in the signature
     * @return string Canonicalized headers as a single string
     */
    private function canonicalizeHeadersSimple(array $headers, array $headersToSign): string
    {
        $out = '';

        // Process headers in the exact order specified
        foreach ($headersToSign as $headerName) {
            if (isset($headers[$headerName])) {
                // CRITICAL: Use the EXACT header value that will be sent
                // Don't just trim - preserve the full value including email format
                $value = trim($headers[$headerName]);
                $out .= strtolower($headerName) . ":" . $value . "\r\n";
            }
        }

        return $out;
    }

    /**
     * Canonicalize body according to simple canonicalization
     */
    private function canonicalizeBody(string $body): string
    {
        // Simple canonicalization rules:
        // 1. Convert all line endings to CRLF
        // 2. Remove all trailing empty lines
        // 3. Body must end with exactly one CRLF

        // Normalize line endings to CRLF
        $body = str_replace(["\r\n", "\r", "\n"], "\r\n", $body);

        // Remove all trailing CRLF sequences
        $body = rtrim($body, "\r\n");

        // Add exactly one CRLF at the end
        $body .= "\r\n";

        return $body;
    }

    /**
     * Clean and format the private key properly
     */
    private function cleanPrivateKey(string $privateKey): string
    {
        // Remove any extra quotes or whitespace
        $key = trim($privateKey, "' \"");

        // Replace literal \n with actual newlines
        $key = str_replace(['\\n', '\n'], "\n", $key);

        // Add PEM headers and format into 64-character lines
        $formattedKey = "-----BEGIN PRIVATE KEY-----\n";
        $formattedKey .= chunk_split($key, 64, "\n");
        $formattedKey .= "-----END PRIVATE KEY-----";

        // Validate the key can be loaded
        $testKey = openssl_pkey_get_private($formattedKey);
        if ($testKey === false) {
            throw new RuntimeException("Invalid private key format: " . openssl_error_string());
        }

        return $formattedKey;
    }

    /**
     * Check if DKIM signing should be applied based on transport and config
     * @param string $transportName Name of the transport being used
     * @param array{
     *   dkim_private_key: string,
     *   dkim_selector: string,
     *   dkim_domain: string
     * } $config Configuration array for the transport
     * @return bool True if DKIM signing should be applied, false otherwise
     */
    public static function shouldSign(string $transportName, array $config): bool
    {
        $localTransports = [NullTransport::class, SendmailTransport::class];
        return !in_array($transportName, $localTransports) && !empty($config['dkim_private_key'])
            && !empty($config['dkim_selector']) && !empty($config['dkim_domain']);
    }
}
