#!/bin/sh
set -e

echo "=== FuImage Docker Entrypoint ==="

# Check if config.json already exists and no ENV override forces regeneration
if [ -f /var/www/html/config/config.json ] && [ -z "$FORCE_RECONFIG" ]; then
    echo "✓ config.json already exists, skipping generation"
else
    echo "Generating config.json from environment variables..."
    php /var/www/html/docker-entrypoint.php
fi

# Ensure data directories exist
mkdir -p /data/images /data/meta
chown -R www-data:www-data /data/images /data/meta

echo "=== Starting PHP-FPM ==="
exec "$@"
