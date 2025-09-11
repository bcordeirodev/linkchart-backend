# 🔒 Correção do Certificado SSL - api.linkcharts.com.br

## 🚨 **PROBLEMA IDENTIFICADO**

O certificado SSL atual **NÃO inclui `api.linkcharts.com.br`** como Subject Alternative Name (SAN).

**Certificado atual cobre apenas:**
- ✅ `linkcharts.com.br`
- ✅ `www.linkcharts.com.br`
- ❌ `api.linkcharts.com.br` ← **FALTANDO**

**Por isso:**
- ❌ Browsers bloqueiam `https://api.linkcharts.com.br`
- ❌ CORS nem é testado (conexão SSL falha primeiro)
- ❌ Front-end não consegue acessar a API

## 🔍 **VERIFICAÇÃO DO PROBLEMA**

```bash
# Comando executado para verificar:
openssl s_client -connect linkcharts.com.br:443 -servername linkcharts.com.br -showcerts 2>/dev/null | openssl x509 -noout -text | grep -A 5 "Subject Alternative Name"

# Resultado:
# X509v3 Subject Alternative Name: 
#     DNS:linkcharts.com.br, DNS:www.linkcharts.com.br
# ← api.linkcharts.com.br NÃO ESTÁ LISTADO
```

## 🛠️ **CORREÇÃO NO SERVIDOR**

### **Passo 1: Adicionar api.linkcharts.com.br ao Certificado**

```bash
# No servidor de produção (como root ou sudo)
sudo certbot certonly --nginx \
  -d linkcharts.com.br \
  -d www.linkcharts.com.br \
  -d api.linkcharts.com.br
```

### **Passo 2: Verificar se o DNS está configurado**

```bash
# Verificar se api.linkcharts.com.br aponta para o servidor
nslookup api.linkcharts.com.br

# Deve retornar o mesmo IP de linkcharts.com.br
```

### **Passo 3: Atualizar configuração do Nginx**

Verificar se existe configuração para `api.linkcharts.com.br`:

```bash
# Verificar se existe arquivo de configuração
ls -la /etc/nginx/sites-available/ | grep api

# Se não existir, criar ou usar a configuração existente
sudo cp /etc/nginx/sites-available/linkcharts /etc/nginx/sites-available/api-linkcharts
```

### **Passo 4: Testar nova configuração**

```bash
# Testar configuração do Nginx
sudo nginx -t

# Se OK, recarregar
sudo systemctl reload nginx
```

### **Passo 5: Verificar se funcionou**

```bash
# Testar certificado
curl -I https://api.linkcharts.com.br/health

# Testar CORS
curl -v -X OPTIONS \
  -H "Origin: https://linkcharts.com.br" \
  -H "Access-Control-Request-Method: POST" \
  "https://api.linkcharts.com.br/api/auth/login"
```

## 🔧 **CONFIGURAÇÃO ATUAL DO PROJETO**

### **Front-end (.env.production):**
```env
VITE_API_URL=https://api.linkcharts.com.br
VITE_BASE_URL=https://linkcharts.com.br
```

### **Back-end (.env.production):**
```env
APP_URL=https://api.linkcharts.com.br
FRONTEND_URL=https://linkcharts.com.br
```

### **CORS (config/cors.php):**
```php
'allowed_origins' => [
    'https://linkcharts.com.br',
    'https://www.linkcharts.com.br',
    // ... outros
],
```

## 🚨 **ALTERNATIVA TEMPORÁRIA (Se não conseguir atualizar certificado)**

### **Opção: Usar Proxy Reverso**

Configurar Nginx para servir API em `https://linkcharts.com.br/api/`:

```nginx
# Em /etc/nginx/sites-available/linkcharts
server {
    listen 443 ssl http2;
    server_name linkcharts.com.br;
    
    # Certificados existentes (funcionam)
    ssl_certificate /etc/letsencrypt/live/linkcharts.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/linkcharts.com.br/privkey.pem;
    
    # Front-end (raiz)
    location / {
        root /path/to/frontend;
        try_files $uri $uri/ /index.html;
    }
    
    # API (proxy para back-end)
    location /api/ {
        proxy_pass http://localhost:8000/api/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**Se usar esta alternativa, atualizar:**

```env
# Front-end
VITE_API_URL=https://linkcharts.com.br/api

# Back-end  
APP_URL=https://linkcharts.com.br/api
```

## ✅ **VERIFICAÇÃO FINAL**

Após a correção, estes comandos devem funcionar:

```bash
# 1. Certificado deve incluir api.linkcharts.com.br
openssl s_client -connect api.linkcharts.com.br:443 -servername api.linkcharts.com.br 2>/dev/null | openssl x509 -noout -text | grep -A 5 "Subject Alternative Name"

# 2. API deve responder
curl -I https://api.linkcharts.com.br/health

# 3. CORS deve funcionar
curl -v -X OPTIONS -H "Origin: https://linkcharts.com.br" "https://api.linkcharts.com.br/api/auth/login" 2>&1 | grep "Access-Control"
```

## 📋 **RESUMO**

**Problema:** Certificado SSL não cobre `api.linkcharts.com.br`
**Solução:** Adicionar `api.linkcharts.com.br` ao certificado SSL existente
**Comando:** `sudo certbot certonly --nginx -d linkcharts.com.br -d www.linkcharts.com.br -d api.linkcharts.com.br`

Após esta correção, a arquitetura funcionará como desejado:
- ✅ Front-end: `https://linkcharts.com.br`
- ✅ Back-end: `https://api.linkcharts.com.br/api/`
