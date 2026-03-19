#!/usr/bin/env bash
# =============================================================================
# Midori Sync — Manual Setup Script
# =============================================================================
set -euo pipefail

echo "╔══════════════════════════════════════╗"
echo "║       Midori Sync — Setup            ║"
echo "╚══════════════════════════════════════╝"
echo ""

# Check prerequisites
command -v php >/dev/null 2>&1 || { echo "Error: PHP 8.3+ is required."; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "Error: Composer is required."; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "Error: Node.js/npm is required."; exit 1; }

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo "→ PHP version: $PHP_VERSION"
echo "→ Node version: $(node --version)"
echo "→ Composer version: $(composer --version --no-ansi | head -1)"
echo ""

# Step 1: Environment file
if [ ! -f .env ]; then
    echo "→ Creating .env from .env.example..."
    cp .env.example .env
    echo "  ⚠  Please edit .env with your database and Authentik credentials."
else
    echo "→ .env file already exists."
fi

# Step 2: Install PHP dependencies
echo ""
echo "→ Installing PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# Step 3: Generate application key
echo ""
echo "→ Generating application key..."
php artisan key:generate --no-interaction

# Step 4: Install frontend dependencies
echo ""
echo "→ Installing frontend dependencies..."
npm ci

# Step 5: Build frontend assets
echo ""
echo "→ Building frontend assets..."
npm run build

# Step 6: Run database migrations
echo ""
echo "→ Running database migrations..."
php artisan migrate --force

# Step 7: Optimize for production
echo ""
echo "→ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo ""
echo "╔══════════════════════════════════════╗"
echo "║       Setup Complete!                ║"
echo "╚══════════════════════════════════════╝"
echo ""
echo "Next steps:"
echo "  1. Edit .env with your Authentik and database credentials"
echo "  2. Configure your Authentik OAuth2 provider (see README.md)"
echo "  3. Run: php artisan serve"
echo "  4. Visit: http://localhost:8000"
echo ""
