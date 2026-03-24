# 🔧 CLI Commands

The MonkeysLegion Mail package provides a range of commands for testing, code generation, and management through the central `ml` CLI tool.

---

## 🚦 All Commands At A Glance

| Command | Description | Example |
|---------|-------------|---------|
| `mail:install` | Install package scaffolding and .env keys | `php ml mail:install` |
| `mail:test <email>` | Send a test email (bypasses queue) | `php ml mail:test user@gmail.com` |
| `make:mail <name>` | Generate a new Mailable class | `php ml make:mail WelcomeMail` |
| `make:dkim-pkey <dir>` | Generate DKIM private/public keys | `php ml make:dkim-pkey storage/keys` |

---

## 🛠️ Code Generation

### Create a Mailable Instance
Generate a pre-filled class with the boilerplate for your custom emails:

```bash
php ml make:mail OrderConfirmed
```
*   Location: `app/Mail/OrderConfirmedMail.php`

---

## 🧪 Installation & Setup

### Full Setup Scaffolding
Running `mail:install` will automate the following tasks:
1.  **Publish** configuration files from stubs.
2.  **Scaffold** a sample email template in `resources/views/emails/`.
3.  **Inject** essential `.env` variables if they are missing.
4.  **Configure** global app settings to register the mail service provider.

```bash
php ml mail:install
```

---

## 🛡️ DKIM Security

### Generate Keys
Create strong 2048-bit RSA keys for your DKIM signing:

```bash
php ml make:dkim-pkey storage/keys
```
*Check `storage/keys/dkim_public.key` to get the value for your DNS TXT record.*

---

## 📈 Queue Management (via framework)

Since mail jobs are handled by the framework's central queue system, use these commands for background process management:

| Command | Description |
|---------|-------------|
| `php ml queue:work emails` | Start a worker for the emails queue |
| `php ml queue:failed` | List and manage failed email jobs |
| `php ml queue:retry --all` | Retry all failed email attempts |
| `php ml queue:clear emails`| Clear all pending email jobs |
| `php ml queue:flush` | Permanently delete all failed jobs |
