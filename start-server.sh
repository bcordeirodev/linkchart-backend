#!/bin/bash

# Script para manter o servidor Laravel rodando
# Reinicia automaticamente se o servidor parar

echo "🚀 Iniciando servidor Laravel com auto-restart..."

# Função para limpar processos antigos
cleanup() {
    echo "🧹 Limpando processos antigos..."
    pkill -f "php artisan serve" 2>/dev/null || true
    sleep 2
}

# Função para iniciar o servidor
start_server() {
    echo "📡 Iniciando servidor na porta 8000..."

    # Limpar cache antes de iniciar
    php artisan config:clear > /dev/null 2>&1
    php artisan cache:clear > /dev/null 2>&1
    php artisan route:clear > /dev/null 2>&1

    # Iniciar servidor
    php artisan serve --host=127.0.0.1 --port=8000 &
    SERVER_PID=$!
    echo "✅ Servidor iniciado com PID: $SERVER_PID"
}

# Função para verificar se o servidor está rodando
check_server() {
    curl -s http://127.0.0.1:8000 > /dev/null 2>&1
    return $?
}

# Função para monitorar o servidor
monitor_server() {
    local restart_count=0
    local max_restarts=10

    while [ $restart_count -lt $max_restarts ]; do
        sleep 5

        if ! check_server; then
            echo "⚠️  Servidor parou de responder. Reiniciando... (tentativa $((restart_count + 1)))"

            # Matar processo antigo se ainda estiver rodando
            if kill -0 $SERVER_PID 2>/dev/null; then
                kill $SERVER_PID 2>/dev/null
                sleep 2
            fi

            # Verificar logs de erro
            echo "📋 Últimos erros do log:"
            tail -5 storage/logs/laravel.log | grep ERROR || echo "Nenhum erro recente encontrado"

            start_server
            restart_count=$((restart_count + 1))
        else
            echo "✅ Servidor respondendo normalmente ($(date))"
        fi
    done

    echo "❌ Máximo de reinicializações atingido. Verifique os logs."
}

# Trap para cleanup ao sair
trap cleanup EXIT

# Limpar processos antigos
cleanup

# Iniciar servidor
start_server

# Aguardar um pouco para o servidor inicializar
sleep 3

# Verificar se iniciou corretamente
if check_server; then
    echo "🎉 Servidor iniciado com sucesso!"
    echo "🌐 Acesse: http://127.0.0.1:8000"
    echo "📊 Monitorando servidor..."
    monitor_server
else
    echo "❌ Falha ao iniciar o servidor. Verificando logs..."
    tail -10 storage/logs/laravel.log
fi
