# SDS-System — Safety Data Sheet Authoring & Generation

A complete, production-ready, locally deployable Safety Data Sheet (SDS) authoring
and generation system for OSHA HazCom 2012 / GHS, tailored for ink & coatings
manufacturers (UV/LED, offset, flexo, aqueous, varnishes).

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    TurnKey LAMP Appliance                        │
│                  (Proxmox VM / Debian-based)                     │
│                                                                  │
│  ┌─────────────┐    ┌──────────────────────────────────────┐    │
│  │  Apache2     │    │  PHP 8.x Application                 │    │
│  │  mod_rewrite │───▶│                                      │    │
│  │  mod_ssl     │    │  ┌────────────┐  ┌───────────────┐  │    │
│  │              │    │  │  Router     │  │  Middleware    │  │    │
│  └─────────────┘    │  │  (Front     │  │  - Auth       │  │    │
│                      │  │  Controller)│  │  - CSRF       │  │    │
│                      │  └─────┬──────┘  │  - RoleGuard  │  │    │
│                      │        │         └───────────────┘  │    │
│                      │        ▼                             │    │
│                      │  ┌─────────────────────────────┐    │    │
│                      │  │      Controllers             │    │    │
│                      │  │  - AuthController            │    │    │
│                      │  │  - RawMaterialController     │    │    │
│                      │  │  - FinishedGoodController    │    │    │
│                      │  │  - FormulaController         │    │    │
│                      │  │  - SDSController             │    │    │
│                      │  │  - LookupController          │    │    │
│                      │  │  - AdminController           │    │    │
│                      │  └─────────┬───────────────────┘    │    │
│                      │            │                         │    │
│                      │            ▼                         │    │
│                      │  ┌─────────────────────────────┐    │    │
│                      │  │      Services                │    │    │
│                      │  │  - FormulaCalcService        │    │    │
│                      │  │  - VOCCalculator             │    │    │
│                      │  │  - HazardEngine              │    │    │
│                      │  │  - SDSGenerator              │    │    │
│                      │  │  - PDFService                │    │    │
│                      │  │  - FederalDataService        │    │    │
│                      │  │    ├─ PubChemConnector       │    │    │
│                      │  │    ├─ NIOSHConnector         │    │    │
│                      │  │    ├─ EPAConnector           │    │    │
│                      │  │    └─ DOTConnector           │    │    │
│                      │  │  - SARA313Service            │    │    │
│                      │  │  - AuditService              │    │    │
│                      │  │  - TranslationService        │    │    │
│                      │  └─────────┬───────────────────┘    │    │
│                      │            │                         │    │
│                      └────────────┼─────────────────────────┘    │
│                                   ▼                              │
│                      ┌──────────────────────┐                    │
│                      │  MariaDB / MySQL      │                    │
│                      │  - sds_system DB      │                    │
│                      │  - InnoDB engine      │                    │
│                      │  - FULLTEXT indexes   │                    │
│                      └──────────────────────┘                    │
│                                                                  │
│  ┌──────────────────┐  ┌──────────────────────────────────┐     │
│  │  Cron Jobs        │  │  File Storage                    │     │
│  │  - refresh federal│  │  - /uploads/supplier-sds/        │     │
│  │  - refresh SARA   │  │  - /generated-pdfs/              │     │
│  │  - housekeeping   │  │  - /storage/logs/                │     │
│  └──────────────────┘  └──────────────────────────────────┘     │
│                                                                  │
│  PDF Generation: TCPDF (pure PHP, no external binary needed)     │
└─────────────────────────────────────────────────────────────────┘
```

## Key Design Decisions

1. **PDF Engine**: TCPDF chosen over wkhtmltopdf/Dompdf for maximum
   compatibility on TurnKey LAMP without external binaries. TCPDF is
   pure PHP and handles multi-page technical documents well.

2. **No ORM / No Framework**: Vanilla PHP with PDO for maximum
   transparency and minimal dependencies. The codebase is structured
   with MVC patterns but avoids heavy frameworks.

3. **Federal Data Priority**: PubChem REST API is the primary federal
   source. NIOSH Pocket Guide data is loaded from their published
   dataset. EPA and DOT connectors are stubbed with the same interface
   for future expansion.

4. **Offline-first**: All federal data is cached locally. SDS generation
   never requires live internet access. Cron refreshes keep data current.

5. **Optimistic Locking**: `updated_at` timestamp comparison prevents
   silent overwrites on concurrent edits.

6. **Immutable Published SDSs**: Published versions create frozen JSON
   snapshots + PDFs that can never be modified.

## Stack

| Component       | Technology                     |
|-----------------|--------------------------------|
| OS              | TurnKey LAMP (Debian)          |
| Web Server      | Apache 2.4 + mod_rewrite       |
| Language        | PHP 8.x                        |
| Database        | MariaDB 10.x / MySQL 8.x       |
| PDF             | TCPDF 6.x                       |
| Auth            | Argon2id password hashing       |
| Sessions        | PHP native sessions (DB-backed) |
| Frontend        | Server-rendered + minimal vanilla JS |

## Directory Layout

```
SDS-System/
├── public/                  # Document root for Apache
│   ├── index.php            # Front controller
│   ├── .htaccess            # URL rewriting
│   ├── css/
│   │   └── app.css          # Application styles
│   ├── js/
│   │   └── app.js           # Minimal vanilla JS
│   ├── uploads/
│   │   └── supplier-sds/    # Uploaded supplier SDS PDFs
│   └── generated-pdfs/      # Generated SDS PDFs
├── src/
│   ├── Core/
│   │   ├── App.php          # Application bootstrap
│   │   ├── Router.php       # URL routing
│   │   ├── Database.php     # PDO wrapper
│   │   ├── Session.php      # Session management
│   │   └── CSRF.php         # CSRF token management
│   ├── Controllers/         # Request handlers
│   ├── Models/              # Data access layer
│   ├── Services/            # Business logic
│   │   └── FederalData/     # Federal data connectors
│   ├── Views/               # PHP templates
│   ├── Middleware/           # Auth, role guards
│   └── Helpers/             # Utility functions
├── config/
│   └── config.php           # Database + app configuration
├── migrations/              # SQL migration files
├── seeds/                   # Seed data SQL
├── cron/                    # Cron job scripts
├── templates/
│   ├── pdf/                 # PDF layout templates
│   └── translations/        # i18n translation files
├── storage/
│   ├── logs/
│   ├── cache/
│   └── temp/
├── vendor/                  # Composer dependencies (TCPDF)
├── composer.json
└── docs/
    └── DEPLOYMENT.md        # TurnKey LAMP deployment guide
```

## Quick Start (Development)

```bash
# 1. Install dependencies
composer install

# 2. Copy and edit config
cp config/config.example.php config/config.php

# 3. Run migrations
php migrations/migrate.php

# 4. Seed initial data
php seeds/seed.php

# 5. Start PHP built-in server (dev only)
php -S localhost:8080 -t public/
```

Default admin credentials: `admin` / `SDS-Admin-2024!`

## Deployment

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for complete TurnKey LAMP
deployment instructions.

## License

Proprietary — Internal use only.
