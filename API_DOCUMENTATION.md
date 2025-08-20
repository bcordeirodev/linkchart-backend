# 📚 Link Charts API - Documentação Completa

## 🚀 Introdução

A **Link Charts API** é uma API RESTful completa para gerenciamento de links encurtados com recursos avançados de analytics, autenticação JWT e rate limiting.

### 🔗 URLs Base
- **Desenvolvimento:** `http://localhost:8000/api`
- **Produção:** `https://api.linkcharts.com`

### 📋 Características Principais
- ✅ **Autenticação JWT** segura
- ✅ **Rate Limiting** inteligente
- ✅ **Analytics avançados** com métricas detalhadas
- ✅ **Parâmetros UTM** para tracking de campanhas
- ✅ **Links com expiração** e ativação programada
- ✅ **Auditoria completa** de todas as operações
- ✅ **Documentação OpenAPI/Swagger**

---

## 🔐 Autenticação

### Como Autenticar

1. **Registre-se** ou faça **login** para obter um token JWT
2. **Inclua o token** no header de todas as requisições protegidas:

```bash
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

### Endpoints de Autenticação

#### 📝 Registrar Usuário
```http
POST /auth/register
Content-Type: application/json

{
  "name": "João Silva",
  "email": "joao@exemplo.com",
  "password": "senha123",
  "password_confirmation": "senha123"
}
```

#### 🔑 Login
```http
POST /auth/login
Content-Type: application/json

{
  "email": "joao@exemplo.com",
  "password": "senha123"
}
```

#### 🔑 Login com Google
```http
POST /auth/google
Content-Type: application/json

{
  "token": "ya29.a0ARrdaM9..."
}
```

#### 👤 Dados do Usuário
```http
GET /me
Authorization: Bearer {token}
```

#### 🚪 Logout
```http
POST /logout
Authorization: Bearer {token}
```

---

## 🔗 Gerenciamento de Links

### ➕ Criar Link Encurtado

**Endpoint:** `POST /gerar-url`  
**Rate Limit:** 30 requisições/minuto  
**Autenticação:** Obrigatória

#### Exemplo Básico
```http
POST /gerar-url
Authorization: Bearer {token}
Content-Type: application/json

{
  "original_url": "https://www.exemplo.com/pagina-muito-longa"
}
```

#### Exemplo Avançado
```http
POST /gerar-url
Authorization: Bearer {token}
Content-Type: application/json

{
  "original_url": "https://www.exemplo.com/produto/123",
  "title": "Produto Especial",
  "description": "Oferta limitada do nosso produto especial",
  "slug": "produto-especial",
  "expires_at": "2024-12-31T23:59:59Z",
  "starts_in": "2024-01-01T00:00:00Z",
  "is_active": true,
  "utm_source": "newsletter",
  "utm_medium": "email",
  "utm_campaign": "black-friday",
  "utm_term": "desconto",
  "utm_content": "botao-principal"
}
```

#### Resposta de Sucesso
```json
{
  "message": "Link criado com sucesso",
  "data": {
    "id": 123,
    "slug": "produto-especial",
    "original_url": "https://www.exemplo.com/produto/123",
    "shorted_url": "https://linkcharts.com/r/produto-especial",
    "title": "Produto Especial",
    "description": "Oferta limitada do nosso produto especial",
    "clicks": 0,
    "is_active": true,
    "expires_at": "2024-12-31T23:59:59Z",
    "created_at": "2024-01-15T10:30:00Z"
  }
}
```

### 📋 Listar Links

**Endpoint:** `GET /link`  
**Autenticação:** Obrigatória

#### Parâmetros de Query
- `page` - Número da página (padrão: 1)
- `per_page` - Itens por página (padrão: 15, máximo: 100)
- `search` - Buscar por título, URL ou slug
- `status` - Filtrar por status (`active`, `inactive`, `all`)

#### Exemplo
```http
GET /link?page=1&per_page=10&search=produto&status=active
Authorization: Bearer {token}
```

### 🔍 Obter Link Específico

**Endpoint:** `GET /link/{id}`  
**Autenticação:** Obrigatória

```http
GET /link/123
Authorization: Bearer {token}
```

### ✏️ Atualizar Link

**Endpoint:** `PUT /link/{id}`  
**Rate Limit:** 20 requisições/minuto  
**Autenticação:** Obrigatória

```http
PUT /link/123
Authorization: Bearer {token}
Content-Type: application/json

{
  "title": "Novo Título",
  "description": "Nova descrição",
  "is_active": false,
  "expires_at": "2024-06-30T23:59:59Z"
}
```

### 🗑️ Excluir Link

**Endpoint:** `DELETE /link/{id}`  
**Rate Limit:** 20 requisições/minuto  
**Autenticação:** Obrigatória

```http
DELETE /link/123
Authorization: Bearer {token}
```

### 📜 Histórico de Auditoria

**Endpoint:** `GET /link/{id}/audit`  
**Autenticação:** Obrigatória

```http
GET /link/123/audit
Authorization: Bearer {token}
```

---

## 📊 Analytics

### 📈 Analytics Gerais

**Endpoint:** `GET /analytics`  
**Autenticação:** Obrigatória

#### Parâmetros de Query
- `period` - Período (`today`, `week`, `month`, `year`, `all`)
- `start_date` - Data início (YYYY-MM-DD)
- `end_date` - Data fim (YYYY-MM-DD)

```http
GET /analytics?period=month
Authorization: Bearer {token}
```

#### Resposta
```json
{
  "total_links": 25,
  "total_clicks": 5420,
  "active_links": 23,
  "avg_clicks_per_link": 216.8,
  "top_links": [
    {
      "id": 123,
      "title": "Meu Link Popular",
      "slug": "meu-link-popular",
      "clicks_count": 892,
      "original_url": "https://exemplo.com"
    }
  ],
  "recent_clicks": [
    {
      "date": "2024-01-15",
      "total": 156
    }
  ],
  "clicks_by_country": [
    {
      "country": "Brasil",
      "total": 2340
    }
  ],
  "clicks_by_device": [
    {
      "device": "mobile",
      "total": 3250
    }
  ]
}
```

### 🎯 Analytics de Link Específico

**Endpoint:** `GET /link/{slug}/analytics`  
**Autenticação:** Obrigatória

```http
GET /link/meu-link/analytics?start_date=2024-01-01&end_date=2024-01-31
Authorization: Bearer {token}
```

---

## 🔄 Redirecionamento

### 🌐 Redirecionar Link (Público)

**Endpoint:** `GET /r/{slug}`  
**Autenticação:** Não requerida

Este endpoint é usado para o redirecionamento real dos links encurtados. Ele:

1. ✅ Registra o clique com dados do visitante
2. ✅ Verifica se o link está ativo
3. ✅ Verifica se o link não expirou
4. ✅ Redireciona para a URL original

```http
GET /r/meu-link-especial
```

#### Parâmetros UTM Adicionais
Você pode adicionar parâmetros UTM extras na URL:

```http
GET /r/meu-link?utm_source=facebook&utm_campaign=post-especial
```

#### Respostas
- **302** - Redirecionamento para URL original
- **404** - Link não encontrado ou inativo
- **410** - Link expirado

### 🔍 Obter Info do Link (Público)

**Endpoint:** `GET /link/by-slug/{slug}`  
**Autenticação:** Não requerida

```http
GET /link/by-slug/meu-link
```

---

## ⚡ Rate Limiting

A API implementa rate limiting para proteger contra abuso:

| Endpoint | Limite | Janela |
|----------|--------|--------|
| `POST /gerar-url` | 30 requisições | 1 minuto |
| `PUT /link/{id}` | 20 requisições | 1 minuto |
| `DELETE /link/{id}` | 20 requisições | 1 minuto |
| Outros endpoints | Sem limite específico | - |

### Headers de Rate Limit
```http
X-RateLimit-Limit: 30
X-RateLimit-Remaining: 25
X-RateLimit-Reset: 1640995200
```

### Resposta de Limite Excedido
```json
{
  "error": "Rate limit exceeded",
  "message": "Muitas tentativas. Tente novamente em alguns minutos.",
  "retry_after": 60
}
```

---

## 🚨 Códigos de Status

| Código | Significado | Descrição |
|--------|-------------|-----------|
| **200** | ✅ OK | Requisição bem-sucedida |
| **201** | ✅ Created | Recurso criado com sucesso |
| **400** | ❌ Bad Request | Erro na requisição |
| **401** | 🔐 Unauthorized | Token não fornecido ou inválido |
| **403** | 🚫 Forbidden | Acesso negado |
| **404** | 🔍 Not Found | Recurso não encontrado |
| **410** | ⏰ Gone | Recurso expirado |
| **422** | ❌ Validation Error | Erro de validação de dados |
| **429** | ⏱️ Too Many Requests | Rate limit excedido |
| **500** | 💥 Internal Error | Erro interno do servidor |

---

## 📝 Exemplos de Uso

### 🔄 Fluxo Completo: Criar e Gerenciar Link

```bash
# 1. Registrar usuário
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "João Silva",
    "email": "joao@exemplo.com",
    "password": "senha123",
    "password_confirmation": "senha123"
  }'

# 2. Fazer login
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "joao@exemplo.com",
    "password": "senha123"
  }'

# Resposta: { "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." }

# 3. Criar link encurtado
curl -X POST http://localhost:8000/api/gerar-url \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." \
  -H "Content-Type: application/json" \
  -d '{
    "original_url": "https://www.meusite.com/produto/123",
    "title": "Produto Incrível",
    "utm_source": "newsletter",
    "utm_campaign": "promocao-janeiro"
  }'

# 4. Listar links
curl -X GET http://localhost:8000/api/link \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."

# 5. Ver analytics
curl -X GET http://localhost:8000/api/analytics?period=month \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

### 📊 Monitoramento de Performance

```javascript
// Exemplo em JavaScript para monitorar cliques
const monitorLink = async (slug) => {
  try {
    const response = await fetch(`/api/link/${slug}/analytics`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      }
    });
    
    const analytics = await response.json();
    
    console.log(`Total de cliques: ${analytics.total_clicks}`);
    console.log(`Visitantes únicos: ${analytics.unique_visitors}`);
    console.log(`Média diária: ${analytics.avg_daily_clicks}`);
    
    return analytics;
  } catch (error) {
    console.error('Erro ao obter analytics:', error);
  }
};
```

### 🎯 Tracking UTM Avançado

```bash
# Criar link com parâmetros UTM completos
curl -X POST http://localhost:8000/api/gerar-url \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "original_url": "https://loja.exemplo.com/produto/smartphone",
    "title": "Smartphone em Promoção",
    "slug": "smartphone-promo",
    "utm_source": "facebook",
    "utm_medium": "social",
    "utm_campaign": "black-friday-2024",
    "utm_term": "smartphone-desconto",
    "utm_content": "post-feed"
  }'

# Link gerado: https://linkcharts.com/r/smartphone-promo
# Ao clicar, redireciona para:
# https://loja.exemplo.com/produto/smartphone?utm_source=facebook&utm_medium=social&utm_campaign=black-friday-2024&utm_term=smartphone-desconto&utm_content=post-feed
```

---

## 🛠️ Ferramentas e Recursos

### 📖 Documentação Interativa

A documentação completa está disponível em formato **OpenAPI/Swagger**:

- **Arquivo:** [`api-documentation.yaml`](./api-documentation.yaml)
- **Swagger UI:** Importe o arquivo YAML em https://editor.swagger.io
- **Postman:** Importe a coleção gerada para testes

### 🧪 Coleção do Postman

```json
{
  "info": {
    "name": "Link Charts API",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "variable": [
    {
      "key": "base_url",
      "value": "http://localhost:8000/api"
    },
    {
      "key": "token",
      "value": ""
    }
  ]
}
```

### 🔍 Testes Automatizados

```bash
# Instalar dependências de teste
npm install -g newman

# Executar testes da API
newman run link-charts-api.postman_collection.json \
  --environment link-charts.postman_environment.json
```

---

## 🚀 Próximos Recursos

### 🔄 Em Desenvolvimento
- [ ] **Webhooks** para notificações em tempo real
- [ ] **Bulk operations** via API
- [ ] **API versioning** (v2)
- [ ] **GraphQL endpoint**
- [ ] **Métricas em tempo real** via WebSockets

### 💡 Roadmap Futuro
- [ ] **Integração com Google Analytics**
- [ ] **A/B testing** para links
- [ ] **Geolocalização avançada**
- [ ] **API de relatórios customizados**
- [ ] **SDK oficial** para JavaScript/Python

---

## 📞 Suporte

### 🆘 Precisa de Ajuda?

- **📧 Email:** support@linkcharts.com
- **📚 Documentação:** https://docs.linkcharts.com
- **🐛 Issues:** https://github.com/linkcharts/api/issues
- **💬 Discord:** https://discord.gg/linkcharts

### 🤝 Contribuindo

Contribuições são bem-vindas! Por favor:

1. Fork o repositório
2. Crie uma branch para sua feature
3. Faça commit das mudanças
4. Abra um Pull Request

---

## 📄 Licença

Este projeto está licenciado sob a **MIT License** - veja o arquivo [LICENSE](LICENSE) para detalhes.

---

**🎉 Pronto para começar? Faça seu primeiro request e comece a encurtar links como um profissional!**
