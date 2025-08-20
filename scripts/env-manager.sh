#!/bin/bash

# Environment Manager for LinkChart API
# Usage: ./scripts/env-manager.sh [action] [environment]

ACTION=${1:-help}
ENVIRONMENT=${2:-production}
PROJECT_ROOT="/var/www/linkchartapi"

show_help() {
    echo "🔧 LinkChart Environment Manager"
    echo ""
    echo "Usage: $0 [action] [environment]"
    echo ""
    echo "Actions:"
    echo "  list       - List all environment files"
    echo "  edit       - Edit environment file safely"
    echo "  switch     - Switch to different environment"
    echo "  backup     - Backup current environment"
    echo "  restore    - Restore from backup"
    echo "  validate   - Validate environment file"
    echo ""
    echo "Environments:"
    echo "  local      - Development environment"
    echo "  staging    - Staging environment"
    echo "  production - Production environment"
    echo ""
}

list_envs() {
    echo "📋 Available environment files:"
    ls -la $PROJECT_ROOT/.env* 2>/dev/null | grep -v ".backup" || echo "No environment files found"
    echo ""
    echo "🔄 Current environment:"
    if [ -f "$PROJECT_ROOT/.env" ]; then
        grep "APP_ENV=" $PROJECT_ROOT/.env 2>/dev/null || echo "APP_ENV not found"
    else
        echo "No .env file active"
    fi
}

edit_env() {
    ENV_FILE="$PROJECT_ROOT/.env.$ENVIRONMENT"
    
    if [ ! -f "$ENV_FILE" ]; then
        echo "❌ Environment file $ENV_FILE not found!"
        exit 1
    fi
    
    # Create backup before editing
    cp "$ENV_FILE" "$ENV_FILE.backup.$(date +%Y%m%d_%H%M%S)"
    echo "✅ Backup created"
    
    # Edit file
    nano "$ENV_FILE"
    
    echo "✅ Environment file edited"
    echo "💡 Run './scripts/env-manager.sh switch $ENVIRONMENT' to apply changes"
}

switch_env() {
    ENV_FILE="$PROJECT_ROOT/.env.$ENVIRONMENT"
    
    if [ ! -f "$ENV_FILE" ]; then
        echo "❌ Environment file $ENV_FILE not found!"
        exit 1
    fi
    
    # Backup current .env
    if [ -f "$PROJECT_ROOT/.env" ]; then
        cp "$PROJECT_ROOT/.env" "$PROJECT_ROOT/.env.backup.$(date +%Y%m%d_%H%M%S)"
        echo "✅ Current environment backed up"
    fi
    
    # Switch environment
    cp "$ENV_FILE" "$PROJECT_ROOT/.env"
    echo "✅ Switched to $ENVIRONMENT environment"
    
    # Restart containers if in production
    if [ "$ENVIRONMENT" = "production" ]; then
        echo "🔄 Restarting containers..."
        docker-compose -f docker-compose.prod.yml restart
        echo "✅ Containers restarted"
    fi
}

backup_env() {
    if [ ! -f "$PROJECT_ROOT/.env" ]; then
        echo "❌ No .env file to backup!"
        exit 1
    fi
    
    BACKUP_NAME=".env.backup.$(date +%Y%m%d_%H%M%S)"
    cp "$PROJECT_ROOT/.env" "$PROJECT_ROOT/$BACKUP_NAME"
    echo "✅ Environment backed up to $BACKUP_NAME"
}

restore_env() {
    echo "📂 Available backups:"
    ls -la $PROJECT_ROOT/.env.backup.* 2>/dev/null | tail -10
    echo ""
    echo "Enter backup filename to restore (or 'latest' for most recent):"
    read -r backup_file
    
    if [ "$backup_file" = "latest" ]; then
        backup_file=$(ls -t $PROJECT_ROOT/.env.backup.* 2>/dev/null | head -1)
    else
        backup_file="$PROJECT_ROOT/$backup_file"
    fi
    
    if [ ! -f "$backup_file" ]; then
        echo "❌ Backup file not found!"
        exit 1
    fi
    
    cp "$backup_file" "$PROJECT_ROOT/.env"
    echo "✅ Environment restored from $backup_file"
}

validate_env() {
    ENV_FILE="$PROJECT_ROOT/.env.$ENVIRONMENT"
    
    if [ ! -f "$ENV_FILE" ]; then
        echo "❌ Environment file $ENV_FILE not found!"
        exit 1
    fi
    
    echo "🔍 Validating $ENV_FILE..."
    
    # Check required variables
    required_vars=("APP_NAME" "APP_ENV" "APP_KEY" "DB_CONNECTION" "DB_HOST" "DB_DATABASE")
    
    for var in "${required_vars[@]}"; do
        if grep -q "^${var}=" "$ENV_FILE"; then
            echo "✅ $var is set"
        else
            echo "❌ $var is missing!"
        fi
    done
    
    # Check for placeholder values
    if grep -q "your-.*-here" "$ENV_FILE"; then
        echo "⚠️ Found placeholder values that need to be updated:"
        grep "your-.*-here" "$ENV_FILE"
    fi
    
    echo "✅ Validation complete"
}

case $ACTION in
    list)
        list_envs
        ;;
    edit)
        edit_env
        ;;
    switch)
        switch_env
        ;;
    backup)
        backup_env
        ;;
    restore)
        restore_env
        ;;
    validate)
        validate_env
        ;;
    help|*)
        show_help
        ;;
esac
