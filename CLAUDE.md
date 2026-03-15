# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PowerMeter is a PHP web application for monitoring mini photovoltaic systems ("Balkonkraftwerk") and other energy consumers/producers. It supports multiple device types: Shelly (3EM, Gen2, legacy), Tasmota, AVM FRITZ!Box + FRITZ!DECT 200/210, Envertech Bridge, AhoyDTU, and ESP-EPEver Controller.

## Architecture

**No build system, package manager, or test framework.** This is a vanilla PHP + JavaScript application deployed directly to an Apache web server.

### Key Files

- **index.php** — Real-time power display with AJAX polling (via `js/index.js`), dark mode, and optional digital clock display (`?d=<digits>`)
- **log.php** — Data collection endpoint called by cronjob every minute. Writes CSV files to `data/YYYY-MM-DD.csv`. Handles external host sync and buffering.
- **chart.php** — Historical chart and statistics generation using Chart.js. Calculates daily aggregates and writes `data/chart_stats.csv`. Supports configurable resolution (1–60 min).
- **overview.php** — Monthly/yearly statistics overview with sortable tables.
- **functions.inc.php** — Shared functions: device communication (`GetStats()`), file scanning, statistics aggregation, date helpers.
- **config.inc.sample** — Configuration template; users copy to `config.inc.php` (gitignored).

### Data Flow

1. Cronjob calls `log.php` every minute → `GetStats()` queries device → CSV row appended to `data/YYYY-MM-DD.csv`
2. `index.php` either calls `GetStats()` live or reads cached `data/stats.txt` (`$use_cache`)
3. `chart.php` reads CSV files, generates Chart.js graphs, and caches daily aggregates in `data/chart_stats.csv`
4. Optional: data is synced to an external host via `$host_external`

### Device Integration

All device drivers live in `GetStats()` in `functions.inc.php`. Each returns an array of values: power (unit1), temperature (unit2), and up to 4 extra measurements (unit3–unit6). Adding a new device means adding a new case to that function.

### Frontend

Vanilla JavaScript with no build step. Chart.js and Tablesort are included as pre-minified files in `js/`. Swipe gestures (`js/swipe.js`) handle mobile chart navigation.

## Running Locally

```bash
# Docker Compose (recommended — data persists in ./data/)
cp config.inc.sample config.inc.php  # edit as needed
docker compose up -d powermeter      # Apache
# or: docker compose --profile frankenphp up -d frankenphp  # FrankenPHP + Swow

# Docker (manual — mount data/ for persistence)
cp config.inc.sample config.inc.php  # edit as needed
docker build . -t powermeter
docker run -p 80:80 -v ./data:/var/www/html/data -v ./config.inc.php:/var/www/html/config.inc.php:ro powermeter

# Or directly with PHP built-in server
cp config.inc.sample config.inc.php  # edit as needed
php -S localhost:8000
```

## Configuration

All configuration is in `config.inc.php` (copied from `config.inc.sample`). Key settings:
- `$device` — device type string
- `$host` — device hostname/IP
- `$log_file_dir` — data directory (default: `data/`)
- `$res` — chart resolution in minutes
- Up to 6 configurable measurement units/labels (`$unit1`–`$unit6`, `$unit1_label`–`$unit6_label`)

## Conventions

- Language: UI strings are in German by default (e.g., "Leistung", "Bezug", "Einspeisung")
- Data storage: flat CSV files, no database; old logs can be gzip-compressed
- Files in `data/` and `config.inc.php` are gitignored
- No external PHP dependencies (no Composer)
- PHP extensions: `mb_string` (FritzBox auth), `gzencode` (optional compression)
