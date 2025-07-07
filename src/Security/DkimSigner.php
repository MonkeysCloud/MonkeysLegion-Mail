<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail\Security;

use MonkeysLegion\Mail\Transport\NullTransport;
use MonkeysLegion\Mail\Transport\SendmailTransport;

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

    public static function generateKeys(int $bits = 2048): array
    {
        $config = [
            "private_key_bits" => $bits,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $private);
        $pubDetails = openssl_pkey_get_details($res);

        return [
            'private' => $private,
            'public' => $pubDetails['key'],
        ];
    }

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

        if (!$signResult) {
            throw new \RuntimeException("Failed to sign DKIM headers: " . openssl_error_string());
        }

        $b64Signature = base64_encode($signature);
        $finalSignature = 'DKIM-Signature: ' . $dkimHeaderBase . $b64Signature;

        return $finalSignature;
    }

    /**
     * Canonicalize headers according to simple canonicalization
     * Preserves header name case and exact formatting
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
            throw new \RuntimeException("Invalid private key format: " . openssl_error_string());
        }

        return $formattedKey;
    }

    public static function shouldSign(string $transportName, array $config): bool
    {
        $localTransports = [NullTransport::class, SendmailTransport::class];
        return !in_array($transportName, $localTransports) && !empty($config['dkim_private_key'])
            && !empty($config['dkim_selector']) && !empty($config['dkim_domain']);
    }
}
