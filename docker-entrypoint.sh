#!/bin/sh
set -e

# Ensure data directory exists and is writable by www-data
DATA_DIR="${PM_DATA_DIR:-/var/www/html/data}"
mkdir -p "$DATA_DIR"
chown -R www-data:www-data "$DATA_DIR" 2>/dev/null || chmod -R 755 "$DATA_DIR"

# Start cron
cron

# Run the main command
exec "$@"
