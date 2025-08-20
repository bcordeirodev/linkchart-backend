#!/bin/bash

# Deploy script for LinkChart API
# Usage: ./scripts/deploy.sh [environment]

set -e

ENVIRONMENT=${1:-production}
PROJECT_ROOT="/var/www/linkchartapi"

echo "🚀 Starting deployment to $ENVIRONMENT environment..."

cd $PROJECT_ROOT

# Backup current .env
if [ -f .env ]; then
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
    echo "✅ Environment backed up"
fi

# Pull latest changes
echo "📦 Pulling latest changes..."
git fetch origin
git reset --hard origin/main

# Copy appropriate environment file
if [ -f ".env.$ENVIRONMENT" ]; then
    cp .env.$ENVIRONMENT .env
    echo "✅ Environment file copied from .env.$ENVIRONMENT"
else
    echo "❌ Environment file .env.$ENVIRONMENT not found!"
    exit 1
fi

# Generate APP_KEY if needed
if grep -q "APP_KEY=base64:your-.*-key-here" .env; then
    echo "🔑 Generating new APP_KEY..."
    docker exec linkchartapi php /var/www/artisan key:generate --force
fi

# Stop containers
echo "🛑 Stopping containers..."
docker-compose -f docker-compose.prod.yml down

# Build and start containers
echo "🏗️ Building and starting containers..."
docker-compose -f docker-compose.prod.yml build --no-cache
docker-compose -f docker-compose.prod.yml up -d

# Wait for containers to be ready
echo "⏳ Waiting for containers to be ready..."
sleep 20

# Run optimizations
echo "⚡ Running Laravel optimizations..."
docker exec linkchartapi php /var/www/artisan config:cache
docker exec linkchartapi php /var/www/artisan route:cache
docker exec linkchartapi php /var/www/artisan view:cache || echo "⚠️ View cache failed (no views directory)"

# Run migrations
echo "🗄️ Running database migrations..."
docker exec linkchartapi php /var/www/artisan migrate --force

# Health check
echo "🏥 Running health check..."
sleep 5
if curl -f http://localhost/health; then
    echo "✅ Deployment successful! Application is healthy."
else
    echo "❌ Health check failed! Rolling back..."
    docker-compose -f docker-compose.prod.yml down
    if [ -f .env.backup.$(date +%Y%m%d)* ]; then
        cp .env.backup.$(ls -t .env.backup.* | head -1) .env
    fi
    docker-compose -f docker-compose.prod.yml up -d
    exit 1
fi

echo "�� Deployment to $ENVIRONMENT completed successfully!"
