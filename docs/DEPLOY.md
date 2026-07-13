# Guia de Deploy — Link Chart

> Como colocar código em produção depois da mudança de 13/07/2026.
> Lê em ~10 minutos. Se você só quer o comando, pule para o [TL;DR](#tldr).

---

## TL;DR

```bash
# 1. Trabalhe normalmente. Merge em main NÃO deploya nada.
git checkout -b feat/minha-feature
# ... código ...
git push                          # roda o CI
# abre PR, merge em main          # roda o CI de novo. E SÓ.

# 2. Quando quiser publicar, crie uma tag:
git tag v1.2.3
git push origin v1.2.3            # ← ISTO deploya

# 3. Deu ruim? Volte para a tag anterior:
gh workflow run "Release (frontend)" -f ref=v1.2.2
gh workflow run "Release (backend)"  -f ref=v2.0.0
```

**Regra de ouro:** *merge* é integrar. *Tag* é publicar. São coisas diferentes agora.

---

## 1. O que era, e por que doía

Antes, o processo era assim:

```
você faz merge em main
        ↓
GitHub entra no servidor por SSH
        ↓
  docker compose down          ← 🔴 SITE SAI DO AR AQUI
        ↓
  docker compose build         ← ...e fica fora por 5 MINUTOS,
        ↓                          porque o build roda no servidor
  docker compose up
        ↓
  site volta
```

Três problemas, um dentro do outro:

**a) O servidor era desligado antes de construir a imagem.** O `down` vinha *antes* do `build`. Não existia motivo para isso — era só a ordem em que o script tinha sido escrito. O site ficava morto durante toda a compilação.

**b) A imagem era construída no próprio servidor de produção.** Seu droplet tem **2 CPUs e 3,8 GB de RAM**, e estava atendendo usuários ao mesmo tempo. Um build de Next.js lá levava **5m44s**. O mesmo build, no runner do GitHub (4 CPUs, 16 GB), leva **2m03s**. Você pagava o triplo de downtime para usar a máquina mais fraca que tinha.

**c) Todo merge era um deploy.** Não havia como integrar código sem publicá-lo.

E não era teoria. Durante a investigação, um deploy seu rodou e o log do nginx registrou:

```
[13/Jul/2026:12:32:28] 502 /api/public/shorten
[13/Jul/2026:12:32:28] 502 /api/public/links/suggest-slug?url=eventos.iff.edu.br...&fbclid=...
```

Um visitante real, vindo do Facebook, tentou encurtar um link no seu site e recebeu erro. **918 respostas 502** no total.

---

## 2. Aula rápida: o que é Blue/Green

A ideia cabe em uma frase: **em vez de consertar o carro em movimento, você prepara um carro novo do lado, e só troca o motorista quando o carro novo já está ligado e funcionando.**

Você mantém **duas cores** do mesmo serviço: `blue` e `green`. Só **uma** recebe tráfego por vez.

### O deploy antigo (recreate) — o que NÃO fazemos mais

```
   usuários ──────► [ BLUE (v1) ]        ← servindo

   1. mata o BLUE
   usuários ──────►      ✗               ← 🔴 FORA DO AR
   2. constrói...                        ← 🔴 ainda fora
   3. sobe o BLUE (v2)
   usuários ──────► [ BLUE (v2) ]        ← voltou
```

Repare no problema estrutural: **você destrói o que funciona antes de saber se o novo funciona.** Se a v2 estiver quebrada, você não tem para onde voltar — está fora do ar até consertar.

### O deploy novo (blue/green) — o que fazemos agora

```
   usuários ──────► [ BLUE (v1) ]  :8000     ← servindo, intocado

   1. sobe o GREEN numa porta livre
   usuários ──────► [ BLUE (v1) ]  :8000     ← ainda servindo!
                    [ GREEN (v2) ] :8001     ← ligando, SEM tráfego

   2. aquece o GREEN: caches, migrations, health check
   usuários ──────► [ BLUE (v1) ]  :8000     ← ainda servindo!
                    [ GREEN (v2) ] :8001     ← "está tudo ok aqui?"

   3. GREEN passou no health check → o nginx vira o tráfego
   usuários ──────► [ GREEN (v2) ] :8001     ← ✅ trocou
                    [ BLUE (v1) ]  :8000     ← ainda vivo, drenando

   4. depois da drenagem, mata o BLUE
   usuários ──────► [ GREEN (v2) ] :8001
```

**Downtime: zero.** Em nenhum instante existiu um momento sem alguém servindo.

### O benefício que vale mais que o zero downtime

Olhe de novo o passo 3. O que acontece **se o GREEN falhar no health check**?

```
   2. aquece o GREEN → 💥 erro! a v2 está quebrada
   3. o deploy ABORTA. O nginx nunca vira.
   usuários ──────► [ BLUE (v1) ] :8000      ← continuou servindo o tempo todo
                    [ GREEN (v2) ] ✗         ← removido, ninguém viu
```

**Um release quebrado nunca chega aos usuários.** Você recebe um e-mail vermelho do GitHub, e o site nem piscou.

Isso não é hipótese: durante a própria implementação, o release `v1.0.0` do frontend **falhou de verdade** (um bug no meu script de rsync). O medidor rodava a cada 2 segundos e registrou **156 de 156 amostras em HTTP 200**. O deploy falhou e ninguém percebeu. No processo antigo, essa mesma falha teria deixado o site fora do ar até alguém acordar e consertar.

### Como o nginx "vira o tráfego"

Essa é a peça central. Existe um arquivo no servidor:

```nginx
# /etc/nginx/conf.d/upstreams.conf
upstream frontend_app { server 127.0.0.1:3000; }   # ← blue
upstream backend_app  { server 127.0.0.1:8000; }   # ← blue
```

Todos os sites do nginx apontam para esses *nomes* (`frontend_app`, `backend_app`), nunca para uma porta fixa. **Esse arquivo é a única fonte de verdade sobre qual cor está ativa.**

Trocar a cor é literalmente trocar um número e mandar o nginx reler:

```bash
sed -i 's/:3000/:3001/' upstreams.conf   # aponta pro green
nginx -s reload                          # ← graceful!
```

O `reload` do nginx é **graceful**: ele não corta as conexões que já estão em andamento. Os processos antigos terminam de atender quem já estava sendo atendido, e só os *novos* pedidos vão para a cor nova. Ninguém leva um tapa na cara no meio de um download.

> **Quer ver qual cor está no ar agora?**
> ```bash
> ssh root@134.209.33.182 'cat /etc/nginx/conf.d/upstreams.conf'
> ```

---

## 3. Aula rápida: por que separar CI de Release

Antes, um único workflow fazia tudo: testava **e** deployava. Isso mistura duas perguntas muito diferentes:

| Pergunta | Quando importa | Deve ser |
|---|---|---|
| "Esse código está correto?" | **toda vez** que você escreve código | automático, rápido, sem consequência |
| "Esse código deve ir para os usuários?" | **quando você decide** | intencional, com um ato explícito |

Agora são dois workflows separados:

```
   push / PR (qualquer branch, inclusive main)
        └─► CI ─────► testes, lint, type-check, guards
                      (NUNCA deploya)

   git tag v1.2.3
        └─► Release ─► constrói a imagem no runner do GitHub
                       └─► publica no GHCR (registro de imagens)
                           └─► servidor faz `pull` e troca a cor
```

**Três ganhos concretos:**

1. **Você acumula merges e solta quando quiser.** Cinco PRs mergeados numa terça? Nenhum deploy. Sexta de manhã, tranquilo, você cria a tag e publica os cinco juntos.
2. **A tag é um ponto de rollback com nome.** "Volte para a `v1.2.2`" é uma frase que o computador entende. "Volte para antes daquele merge de terça" não é.
3. **O frontend ganhou CI em PR** — ele **não tinha nenhum**. Antes, a primeira vez que qualquer coisa era verificada no frontend era... já em produção, deployando.

### Onde a imagem é construída — e por que isso importa tanto

O CI constrói a imagem Docker e a publica no **GHCR** (GitHub Container Registry — um "armário" de imagens prontas).

```
ANTES:  servidor recebe o CÓDIGO-FONTE ──► servidor compila (6 min, sofrendo) ──► roda
DEPOIS: runner do GitHub compila ──► publica imagem pronta ──► servidor só faz `pull` (20s)
```

O servidor **nunca mais compila nada**. Ele baixa uma imagem já construída e testada, e troca o container. É por isso que o trabalho no droplet caiu de ~6 minutos para ~35 segundos.

Bônus: como cada imagem é marcada com o SHA do commit, **rollback vira "baixar a imagem antiga"**, não "reverter o commit e esperar 6 minutos de rebuild".

---

## 4. O que você precisa fazer no dia a dia

### Fluxo normal

```bash
# 1. Trabalhe numa branch
git checkout -b feat/minha-feature
# ... código ...
git push                     # CI roda: testes, lint, guards

# 2. Abra o PR, faça o merge em main
#    → o CI roda de novo. NENHUM deploy acontece.

# 3. Quando quiser publicar:
git tag v1.2.3
git push origin v1.2.3       # ← isto dispara build + deploy
```

**Como numerar as tags?** Use [semver](https://semver.org) de forma simples:
- `v1.2.3` → `v1.2.4` — correção de bug
- `v1.2.3` → `v1.3.0` — funcionalidade nova
- `v1.2.3` → `v2.0.0` — mudança grande/estrutural

Os dois repos têm numeração **independente** (o backend está em `v2.x`, o frontend em `v1.x`). Tudo bem — são produtos separados.

### Acompanhar o deploy

```bash
gh run watch                 # acompanha ao vivo no terminal
```

Ou no navegador: aba **Actions** do repo.

### Rollback (quando algo passou pelos testes mas quebrou em produção)

```bash
gh workflow run "Release (backend)" -f ref=v2.0.0    # volta pra tag anterior
```

Leva o mesmo tempo de um deploy normal (~5 min), **e também é zero downtime**.

### Deploy manual sem criar tag

```bash
gh workflow run "Release (frontend)" -f ref=main
```

---

## 5. As três regras novas (leia com atenção)

### 🔴 Regra 1 — Migrations precisam ser retrocompatíveis

**Por quê:** no blue/green, a migration roda **enquanto o código antigo ainda está servindo usuários**. Se você apagar uma coluna que a v1 ainda usa, a v1 quebra na hora — e ela é quem está atendendo.

**O que fazer** (chama-se *expand/contract*):

```
❌ ERRADO — em um único release:
   renomear a coluna `nome` para `nome_completo`
   → a v1, que ainda serve, procura `nome` e quebra

✅ CERTO — em dois releases:
   Release 1:  adiciona a coluna `nome_completo` (a v1 ignora, a v2 usa)
               → deploy, tudo funciona
   Release 2:  agora que ninguém mais usa `nome`, remove
               → deploy, tudo funciona
```

**A boa notícia: você já faz isso.** Analisei suas 34 migrations — **nenhuma** delas tem `dropColumn`, `renameColumn` ou `drop table` no `up()`. As únicas 3 que usam `->change()` estão *alargando* coisas (deixar um campo nullable, aumentar um varchar), o que nunca quebra código antigo. Essa regra não muda nada no seu jeito de trabalhar; ela só impede um acidente futuro.

**Se você tentar quebrar a regra, o CI te barra:**

```
FAIL  Tests\Unit\MigrationSafetyTest
Migration destrutiva no up(). O deploy e blue/green: isto roda com o
codigo ANTIGO ainda servindo e vai quebra-lo.
```

**E se você REALMENTE precisar de uma migration destrutiva?** Existe a saída de emergência:

1. Marque a migration com o comentário `@offline-migration`
2. Dispare o release com `migration_mode: offline`

Isso para a aplicação, migra e sobe de novo (~20s de downtime). É consciente e pontual, não um hábito.

### 🔴 Regra 2 — Toda `NEXT_PUBLIC_*` precisa de um `ARG` no Dockerfile

**Esta regra te custou dinheiro real.** Deixa eu contar o que aconteceu.

Você configurou os rótulos de conversão do Google Ads no `.env.production`. Corretamente. Mas:

1. O `.dockerignore` **exclui** o `.env.production` do build do Docker.
2. O `Dockerfile` **não declarava** `ARG NEXT_PUBLIC_GADS_CONV_SHORTEN`.
3. E o Docker **ignora em silêncio** qualquer `--build-arg` sem `ARG` correspondente. Sem erro. Sem aviso.

Resultado: a variável compilava como **string vazia**, e o `gtag` disparava a conversão com `send_to: ""` — para lugar nenhum. Sua campanha rodou semanas gastando dinheiro **sem registrar uma única conversão**.

E o mais cruel: **em desenvolvimento funcionava perfeitamente** (o `next build` na sua máquina lê o `.env` normalmente). Só quebrava dentro do Docker — que é justamente o que vai para produção.

**Agora existe um guard:** ao adicionar uma `process.env.NEXT_PUBLIC_ALGUMA_COISA` no código, você precisa de duas linhas a mais:

```dockerfile
# frontend-next/Dockerfile
ARG NEXT_PUBLIC_ALGUMA_COISA
```
```yaml
# frontend-next/.github/workflows/release.yml
build-args: |
  NEXT_PUBLIC_ALGUMA_COISA=${{ vars.NEXT_PUBLIC_ALGUMA_COISA }}
```

Se esquecer, o CI reprova:

```
ERRO: variaveis NEXT_PUBLIC_* lidas pelo codigo mas SEM 'ARG' no Dockerfile.
O Docker vai ignorar a --build-arg em silencio e elas compilarao VAZIAS
em producao — sem erro, sem aviso, e funcionando normalmente em dev.
```

### 🔴 Regra 3 — A stack `infra` sobe com `-p linkchartapi`. Sempre.

**Esta é a que apaga o banco se errar.**

Os volumes do Docker são "escopados" pelo nome do projeto Compose. O seu banco vive num volume chamado `linkchartapi_postgres_data` — e esse prefixo vem do `-p linkchartapi`.

```bash
✅ docker compose -p linkchartapi -f docker-compose.infra.yml up -d
     → usa o volume linkchartapi_postgres_data     (seus dados, 8.966 cliques)

❌ docker compose -f docker-compose.infra.yml up -d
     → usa o volume backend_postgres_data          (NOVO, VAZIO)
     → o Docker cria sem reclamar
     → o banco PARECE APAGADO
```

Ninguém te avisa. O Postgres sobe feliz, com um banco vazio. Por isso o `deploy.sh` já traz o `-p linkchartapi` embutido e há um aviso em cima da linha. **Se um dia você mexer nessa stack na mão, confira o `-p`.**

---

## 6. Como está montado (mapa)

### Frontend (`frontend-next/`)

| Arquivo | Papel |
|---|---|
| `.github/workflows/ci.yml` | Gate de qualidade em PR/push. **Nunca deploya.** |
| `.github/workflows/release.yml` | Tag `v*` → constrói a imagem → GHCR → deploy |
| `docker-compose.prod.yml` | Consome a imagem pronta; porta parametrizada por cor |
| `scripts/deploy.sh` | O cutover blue/green (3000 ↔ 3001) |
| `scripts/check-build-args.sh` | Guard da Regra 2 |

### Backend (`backend/`)

| Arquivo | Papel |
|---|---|
| `.github/workflows/ci.yml` | Testes (SQLite **e** Postgres), Pint, guard de migrations |
| `.github/workflows/release.yml` | Tag `v*` → imagem → GHCR → deploy |
| `docker-compose.infra.yml` | **Postgres, Redis, Alloy.** Sobe uma vez. **Deploy nunca toca.** |
| `docker-compose.app.yml` | Web (nginx+php-fpm). **Blue/green** (8000 ↔ 8001) |
| `docker-compose.worker.yml` | Filas + scheduler. **Instância única** |
| `scripts/deploy.sh` | O cutover blue/green |
| `scripts/inject-env.sh` | Injeta os secrets no `.env.production` |
| `tests/Unit/MigrationSafetyTest.php` | Guard da Regra 1 |

### Por que o worker ficou num container separado?

Porque no blue/green existem, por alguns segundos, **duas cores da web no ar ao mesmo tempo**. Se as filas e o scheduler morassem dentro do container web, você teria **dois schedulers rodando em paralelo** — e o `LinkHealthCheckJob` (de hora em hora) e o `clicks:anonymize-ips` (diário) poderiam **disparar em duplicidade**.

Com o worker separado, o blue/green se aplica só à web (que é *stateless* e não liga de ter duas cópias), e o worker é recriado de forma simples. Durante a troca, os jobs apenas **esperam no Redis** — nenhum usuário percebe.

---

## 7. Diagnóstico rápido

**Qual cor está no ar?**
```bash
ssh root@134.209.33.182 'cat /etc/nginx/conf.d/upstreams.conf'
```

**O que está rodando?**
```bash
ssh root@134.209.33.182 'docker ps --filter name=linkchart --format "{{.Names}}\t{{.Status}}"'
```
Você deve ver: uma cor do backend, uma do frontend, **um** worker, e a infra (db/redis/alloy) com **uptime longo** — se o Postgres tiver reiniciado junto com um deploy, algo está errado.

**O deploy falhou. O site caiu?**
Provavelmente **não** — esse é o ponto do blue/green. Confirme:
```bash
curl -o /dev/null -w "%{http_code}\n" https://linkcharts.com.br/api/health
curl -o /dev/null -w "%{http_code}\n" https://api.linkcharts.com.br/health
```
Se der `200`, a cor antiga está servindo. Leia o log do Actions com calma, corrija, e crie uma tag nova. Sem pressa, sem incêndio.

**Emergência: voltar para a versão anterior agora**
```bash
gh workflow run "Release (backend)" -f ref=v2.0.0
```

---

## 8. Resumo do que mudou

| | Antes | Agora |
|---|---|---|
| Downtime (frontend) | ~5 min | **0s** |
| Downtime (backend **+ banco**) | ~2,5 min | **0s** |
| Onde a imagem é construída | no servidor de produção 😬 | no runner do GitHub |
| Trabalho do droplet | build de ~6 min | `pull` + troca (~35s) |
| Deploy dispara | **todo push em `main`** | tag `v*` (ou botão) |
| Release quebrado | **site fora do ar** | aborta; site nem pisca |
| Rollback | reverter commit + rebuild | deploy da tag anterior |
| Banco durante o deploy | **reiniciado junto** | intocado |
| CI em PR do frontend | **nenhum** | type-check, lint, format, guard |

Medido em produção: **634 amostras** durante os deploys do frontend e **401** durante os do backend (API, site e redirect ao mesmo tempo) — **todas HTTP 200**.
