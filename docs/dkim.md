# 🛡️ DKIM Email Signing

DKIM (DomainKeys Identified Mail) adds digital signatures to your messages, preventing spoofing and ensuring that they reach the recipient's **Inbox** instead of the **Spam** folder.

---

## 🚀 Why DKIM Matters

-   **Enhanced Deliverability**: Modern providers like Gmail/Outlook use DKIM to verify the sender.
-   **Spoofing Prevention**: Attackers cannot fake your domain for email sending.
-   **Reputation Management**: Your domain's identity is cryptographically tied to your messages.

---

## 🗝️ Setting Up DKIM

### 1. Generate DKIM Keys
You can use the built-in CLI command to generate a key pair in a specified directory:

```bash
php ml make:dkim-pkey storage/keys
```

This will create:
-   `dkim_private.key` (Keep this secure; it's your signing key)
-   `dkim_public.key` (The public key for your DNS record)

---

### 2. DNS Configuration
Add a **TXT** record to your domain's DNS:

-   **Type**: TXT
-   **Host (Name)**: `default._domainkey` (If using 'default' selector)
-   **Value**: `v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY_CONTENT_HERE`

---

### 3. Environment Configuration
Update your `.env` to use the private key (Note: Use the **raw private key data** without headers like `-----BEGIN RSA PRIVATE KEY-----`):

```env
MAIL_DKIM_PRIVATE_KEY=MIICeAIBADANBgkqhkiG9w0BAQEFAASCAmIwggJeAgEAAoGBAMODcNBCB7...
MAIL_DKIM_SELECTOR=default
MAIL_DKIM_DOMAIN=yourdomain.com
```

---

## 🎯 Verification

Test your DKIM signing by sending an email via the CLI:

```bash
php ml mail:test test@gmail.com
```

Check the original headers of the received message for:
```
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=yourdomain.com; s=default; h=From:To:Subject:Date:Message-ID:Content-Type:MIME-Version; bh=...; b=...
```

---

## 🛠️ Features

- **Automatic Signing**: When configured, all emails are automatically signed regardless of the transport (SMTP, MonkeysMail, Sendmail).
- **Manual Signing**: Use the `DkimSigner` class if you wish to sign messages manually.
- **Queue Compatibility**: DKIM signatures are preserved through the queue processing lifecycle.
- **Raw Key Support**: Simplified configuration—just paste the actual key data.
