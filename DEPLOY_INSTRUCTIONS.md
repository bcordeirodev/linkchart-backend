# 🚀 **INSTRUÇÕES DE DEPLOY - DIGITALOCEAN**

**Servidor**: `138.197.121.81`  
**Senha**: `REDACTED_PASSWORD`

---

## 📋 **COMANDOS PARA EXECUTAR NO SEU COMPUTADOR**

### **1. Conectar no servidor:**
```bash
ssh root@138.197.121.81
# Senha: REDACTED_PASSWORD
```

### **2. Executar configuração inicial:**
```bash
# Baixar script de configuração
curl -O https://raw.githubusercontent.com/bcordeirodev/linkchart-backend/main/setup-digitalocean.sh

# Tornar executável e executar
chmod +x setup-digitalocean.sh
./setup-digitalocean.sh
```

### **3. Mudar para usuário da aplicação:**
```bash
su - linkchartapp
cd /var/www/linkchartapi
```

### **4. Configurar ambiente:**
```bash
# Executar configuração do ambiente
./configure-env.sh
```

### **5. Fazer deploy:**
```bash
# Executar deploy final
./deploy-production.sh
```

---

## 🎯 **PROCESSO COMPLETO EM UM COMANDO**

Se preferir executar tudo de uma vez:

```bash
# Conectar no servidor
ssh root@138.197.121.81

# Executar tudo automaticamente
curl -sSL https://raw.githubusercontent.com/bcordeirodev/linkchart-backend/main/setup-digitalocean.sh | bash && \
su - linkchartapp -c "cd /var/www/linkchartapi && ./configure-env.sh && ./deploy-production.sh"
```

---

## ✅ **APÓS O DEPLOY**

### **URLs de acesso:**
- **API**: http://138.197.121.81
- **Health Check**: http://138.197.121.81/api/health
- **Database**: 138.197.121.81:5432
- **Redis**: 138.197.121.81:6379

### **Comandos úteis:**
```bash
# Ver logs
docker compose -f docker-compose.prod.yml logs -f

# Status dos containers
docker compose -f docker-compose.prod.yml ps

# Reiniciar aplicação
docker compose -f docker-compose.prod.yml restart

# Parar aplicação
docker compose -f docker-compose.prod.yml down
```

---

## 🔧 **CONFIGURAÇÕES AUTOMÁTICAS**

O script automaticamente:
- ✅ Instala Docker e dependências
- ✅ Configura firewall (UFW)
- ✅ Cria usuário `linkchartapp`
- ✅ Clona repositório
- ✅ Gera senhas seguras
- ✅ Configura variáveis de ambiente
- ✅ Executa migrações
- ✅ Otimiza para produção
- ✅ Testa saúde da aplicação

---

## 📊 **CREDENCIAIS GERADAS**

As credenciais serão salvas automaticamente em:
`/var/www/linkchartapi/credentials.txt`

---

## 🚨 **TROUBLESHOOTING**

### **Se algo der errado:**
```bash
# Ver logs detalhados
docker compose -f docker-compose.prod.yml logs

# Reiniciar tudo
docker compose -f docker-compose.prod.yml down
docker compose -f docker-compose.prod.yml up -d --build

# Verificar status
docker compose -f docker-compose.prod.yml ps
```

### **Testar API manualmente:**
```bash
curl http://138.197.121.81/api/health
```

---

## 🎉 **PRÓXIMOS PASSOS**

1. **Testar API**: Acessar http://138.197.121.81/api/health
2. **Configurar Frontend**: Apontar para http://138.197.121.81
3. **Configurar Domínio**: (opcional) Apontar domínio para o IP
4. **SSL**: (opcional) Configurar certificado SSL

---

## 📞 **SUPORTE**

Se precisar de ajuda, me forneça:
- Logs: `docker compose logs`
- Status: `docker compose ps`
- Erro específico que está ocorrendo
