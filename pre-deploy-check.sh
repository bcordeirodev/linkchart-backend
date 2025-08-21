#!/bin/bash

# ======================================================
# SCRIPT DE VERIFICAÇÃO PRÉ-DEPLOY - LINK CHART
# ======================================================

echo "🔍 PRÉ-DEPLOY CHECK - LINK CHART"
echo "==============================="
echo "Data: $(date)"
echo ""

ERRORS=0

# 1. Verificar se .env.production existe
echo -n "🔍 Verificando .env.production: "
if [ -f ".env.production" ]; then
    echo "✅ EXISTE"
else
    echo "❌ NÃO ENCONTRADO"
    ERRORS=$((ERRORS + 1))
fi

# 2. Verificar JWT_SECRET no .env.production
echo -n "🔍 Verificando JWT_SECRET: "
if grep -q "JWT_SECRET=SPF" .env.production; then
    echo "✅ CORRETO"
else
    echo "❌ INCORRETO"
    ERRORS=$((ERRORS + 1))
fi

# 3. Verificar LOG_CHANNEL
echo -n "🔍 Verificando LOG_CHANNEL: "
LOG_CHANNEL=$(grep "LOG_CHANNEL=" .env.production | cut -d'=' -f2)
if [ "$LOG_CHANNEL" = "production" ]; then
    echo "✅ PRODUCTION CHANNEL"
elif [ "$LOG_CHANNEL" = "daily" ]; then
    echo "✅ DAILY CHANNEL (backup)"
else
    echo "❌ CHANNEL INVÁLIDO ($LOG_CHANNEL)"
    ERRORS=$((ERRORS + 1))
fi

# 4. Verificar docker-compose.prod.yml
echo -n "🔍 Verificando docker-compose.prod.yml: "
if [ -f "docker-compose.prod.yml" ]; then
    echo "✅ EXISTE"
else
    echo "❌ NÃO ENCONTRADO"
    ERRORS=$((ERRORS + 1))
fi

# 5. Verificar se env_file está configurado
echo -n "🔍 Verificando env_file no docker-compose: "
if grep -q "env_file:" docker-compose.prod.yml; then
    echo "✅ CONFIGURADO"
else
    echo "❌ NÃO CONFIGURADO"
    ERRORS=$((ERRORS + 1))
fi

# 6. Verificar variáveis críticas
echo "🔍 Verificando variáveis críticas:"
for var in "APP_ENV=production" "APP_DEBUG=false" "DB_HOST=database" "REDIS_HOST=redis"; do
    echo -n "  • $var: "
    if grep -q "$var" .env.production; then
        echo "✅"
    else
        echo "❌"
        ERRORS=$((ERRORS + 1))
    fi
done

# 7. Verificar se Dockerfile existe
echo -n "🔍 Verificando Dockerfile: "
if [ -f "Dockerfile" ]; then
    echo "✅ EXISTE"
else
    echo "❌ NÃO ENCONTRADO"
    ERRORS=$((ERRORS + 1))
fi

echo ""
echo "📊 RESUMO:"
echo "========"

if [ $ERRORS -eq 0 ]; then
    echo "✅ TUDO OK! Pronto para deploy."
    exit 0
else
    echo "❌ $ERRORS erro(s) encontrado(s). Corrija antes do deploy."
    echo ""
    echo "🔧 SUGESTÕES DE CORREÇÃO:"
    echo "========================"

    if ! grep -q "JWT_SECRET=SPF" .env.production; then
        echo "• Corrigir JWT_SECRET:"
        echo "  sed -i 's/JWT_SECRET=.*/JWT_SECRET=SPFbPErKrHyA5rc0Lwswwbs2EXVlwkC1DckzF6wb8CwpUxf4yLYjMtiJOVhRtWnW1A2B3C4D5E6F7G8H9I0J1K2L3M4N5O6P7Q8R9S0T/' .env.production"
    fi

    if [ "$LOG_CHANNEL" != "production" ] && [ "$LOG_CHANNEL" != "daily" ]; then
        echo "• Corrigir LOG_CHANNEL:"
        echo "  sed -i 's/LOG_CHANNEL=.*/LOG_CHANNEL=daily/' .env.production"
    fi

    if ! grep -q "env_file:" docker-compose.prod.yml; then
        echo "• Adicionar env_file no docker-compose.prod.yml"
    fi

    exit 1
fi
