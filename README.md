# Statistica GVVT

Flight statistics tool for [GVVT](https://gvvt.ch) (Gruppo Volo a Vela Ticino), built on the [Vereinsflieger](https://www.vereinsflieger.de) REST API.

Authenticates club members, fetches all flights for a selected month, assembles tow-plane/glider pairs, displays a landing summary table, and offers a downloadable `.xlsx` report.

---

## Requirements

- PHP 8.1+
- Extensions: `curl`, `zip`, `mbstring`, `xml` (all standard on shared hosting)
- [Composer](https://getcomposer.org) (only needed locally or in CI — not on the server)

---

## Local setup

```bash
git clone https://github.com/giorgix3/gvvt-statistica-vf.git
cd gvvt-statistica-vf

# Install dependencies
composer install

# Configure secrets
cp .env.example .env
# Edit .env and fill in VF_APP_KEY and VF_CLUB_ID
```

Then serve the `v2/` folder with any local PHP server:

```bash
php -S localhost:8000 -t .
```

---

## Configuration

Copy `.env.example` to `.env` and set the two required variables:

| Variable | Description |
|---|---|
| `VF_APP_KEY` | Vereinsflieger application key (issued per third-party app) |
| `VF_CLUB_ID` | Vereinsflieger club ID (e.g. `1099`) |

The `.env` file is listed in `.gitignore` and must **never be committed**.

On the live server you can either upload a `.env` file via FTP or set the variables directly in the hosting control panel — real environment variables always take precedence over `.env`.

---

## Deployment

### Manual (FTP upload)

1. Run `composer install --no-dev --optimize-autoloader` locally
2. Upload the entire project folder (including `vendor/`) to your hosting via FTP/SFTP
3. Create a `.env` file on the server with the real values

### Automatic (GitHub Actions)

Every push to `main` triggers the [deploy workflow](.github/workflows/deploy.yml), which:

1. Installs PHP dependencies via Composer
2. Writes a `.env` from GitHub Secrets (secrets never touch the repo)
3. Uploads only changed files to the hosting via FTP

**Required repository secrets** (Settings → Secrets and variables → Actions):

| Secret | Description |
|---|---|
| `VF_APP_KEY` | Vereinsflieger app key |
| `VF_CLUB_ID` | Vereinsflieger club ID |
| `FTP_HOST` | FTP hostname of your hosting provider |
| `FTP_USER` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_PATH` | Remote path, e.g. `/public_html/statistica/` |

---

## Project structure

```
├── .env.example              # Secret template — safe to commit
├── .github/
│   └── workflows/
│       └── deploy.yml        # CI/CD pipeline
├── composer.json
├── composer.lock
├── index.php                 # Login page
├── report.php                # Month picker + summary table
├── download.php              # Streams the .xlsx file
├── logout.php
└── src/
    ├── Env.php               # Minimal .env loader (no external deps)
    ├── VereinsfliegerClient.php   # REST API client (parallel curl_multi)
    └── FlightAssembler.php        # Flight pairing + statistics logic
```

---

## Key improvements over v1

| v1 (Python CGI) | v2 (PHP) |
|---|---|
| CGI module removed in Python 3.13 | Standard PHP — works on any shared host |
| Token passed in the URL | Token stored server-side in `$_SESSION` |
| 28–31 sequential API calls per month | Parallel `curl_multi_exec` — ~10× faster |
| Secrets hardcoded in source | Read from environment / `.env` |
| `SSL_VERIFYPEER = false` | Full TLS verification enabled |
| Mixed PHP + Python split | Single language, single process |
