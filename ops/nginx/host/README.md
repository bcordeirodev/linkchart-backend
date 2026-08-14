# Nginx do host (droplet) — configuração versionada

Estes arquivos vivem em `/etc/nginx/` no droplet de produção (`<DEPLOY_HOST>`) e
**não são aplicados por nenhum deploy**. O `scripts/deploy.sh` só reescreve o
`conf.d/upstreams.conf`; todo o resto é editado à mão no servidor.

Este diretório existe porque essa configuração ficou fora do git até 2026-07-26, e
isso teve custo real: a ausência de `real_ip_header` era a causa de o IP do cliente
ser forjável, e levou uma investigação inteira para ser encontrada, porque não havia
onde ler a config sem entrar no servidor.

## Mapa

| Arquivo aqui | Caminho no droplet | Papel |
|---|---|---|
| `conf.d/cloudflare-realip.conf` | `/etc/nginx/conf.d/` | faixas da Cloudflare + `real_ip_header CF-Connecting-IP` |
| `conf.d/upstreams.conf` | `/etc/nginx/conf.d/` | **estado de runtime** — ver aviso abaixo |
| `sites-enabled/api-linkcharts` | `/etc/nginx/sites-enabled/` | vhost `api.linkcharts.com.br` |
| `sites-enabled/linkchart-frontend` | `/etc/nginx/sites-enabled/` | vhost `linkcharts.com.br` |
| `sites-enabled/redirect-linkcharts` | `/etc/nginx/sites-enabled/` | vhost `redirect.linkcharts.com.br` (hot path de cliques) |
| `sites-enabled/subdomains-linkcharts` | `/etc/nginx/sites-enabled/` | vhost wildcard dos subdomínios custom |

> ⚠️ **`upstreams.conf` NÃO é fonte de verdade.** Ele é a fonte de verdade da cor
> ativa (blue/green) e é reescrito pelo `scripts/deploy.sh` a cada release. A cópia
> aqui é só referência do formato — **nunca** aplique este arquivo por cima do que
> está no servidor, ou você pode apontar o Nginx para a cor errada.

## Por que `real_ip_header CF-Connecting-IP`

Todo o tráfego passa pela Cloudflare. Sem `set_real_ip_from`, o `$remote_addr` do
Nginx é a **borda da Cloudflare**, não o visitante — e a aplicação passa a gravar
esse endereço (ou, pior, a confiar no primeiro token do `X-Forwarded-For`, que o
cliente controla). Com a diretiva restrita às faixas da CF, o Nginx só honra o
header quando o peer é de fato a Cloudflare: uma requisição direta na origem mantém
o `$remote_addr` verdadeiro do atacante e não consegue forjar nada.

Detalhe: a aplicação depende disto. `TrustProxies` confia em `172.16.0.0/12` (as
bridges do Docker) e o `ClientIpResolver` delega em `$request->ip()`. Ver
`docs/superpowers/specs/2026-07-25-ip-resolution-anti-abuse-design.md` no repo raiz.

## Como aplicar uma mudança

```bash
# 1. edite o arquivo AQUI e commite (para o histórico existir)
# 2. copie para o droplet
scp -i ~/.ssh/id_ed25519 ops/nginx/host/conf.d/cloudflare-realip.conf \
    root@<DEPLOY_HOST>:/etc/nginx/conf.d/

# 3. VALIDE antes de recarregar — sem isto, um erro de sintaxe derruba o site
ssh -i ~/.ssh/id_ed25519 root@<DEPLOY_HOST> "nginx -t"

# 4. só então recarregue (graceful, sem downtime)
ssh -i ~/.ssh/id_ed25519 root@<DEPLOY_HOST> "nginx -s reload"

# 5. confirme que subiu
curl -s -o /dev/null -w "%{http_code}\n" https://api.linkcharts.com.br/health
```

Se o `nginx -t` falhar, **não recarregue**: sem reload, nada em produção mudou.

## Atualizar as faixas da Cloudflare

Elas mudam raramente, mas mudam — e se envelhecerem o geo **degrada em silêncio**,
porque o Nginx volta a entregar a borda da CF como se fosse o cliente. O alarme é o
evento `ip.edge_chain_mismatch` no canal `app` (a aplicação compara o IP resolvido
com o header `CF-Connecting-IP`): se ele aparecer em quase toda requisição, a lista
está velha.

Fontes: <https://www.cloudflare.com/ips-v4> e <https://www.cloudflare.com/ips-v6>.
Última atualização: **2026-05-27** (22 faixas: 15 IPv4 + 7 IPv6).

## Firewall: 80/443 só aceitam a Cloudflare

Desde **2026-07-26** o `ufw` só permite `80/tcp` e `443/tcp` a partir das faixas da
Cloudflare (22 regras com o comentário `cloudflare`). Antes disso era possível falar
direto com a origem e **pular a Cloudflare**, o que também pulava o WAF e a proteção
de DDoS. Verificado na aplicação: via Cloudflare `200`; direto no IP do droplet,
timeout.

⚠️ **Isto eleva o custo de uma lista velha.** Antes, faixa faltando significava geo
degradado; agora significa **visitante bloqueado**. A mesma lista alimenta o
`set_real_ip_from` e o `ufw` — mantenha as duas em sincronia.

Também foram removidas as regras órfãs de `3000/tcp` e `8080/tcp`, que estavam
abertas para qualquer origem sem nada escutando nelas publicamente.

Renovação de certificado sobrevive a isso: o wildcard usa **DNS-01** (hook da
Cloudflare) e o do apex usa HTTP-01 pelo plugin do nginx — como o DNS é proxiado, o
desafio chega pela borda, de um IP permitido. Se algum dia o apex sair do proxy da
Cloudflare (nuvem cinza), a renovação passa a falhar em silêncio.

```bash
# reverter (reabre a origem para qualquer um)
ssh -i ~/.ssh/id_ed25519 root@<DEPLOY_HOST> "ufw allow 80/tcp && ufw allow 443/tcp"

# reaplicar as faixas (adicione ANTES de remover as permissivas)
for r in <faixas>; do ufw allow proto tcp from "$r" to any port 80,443 comment cloudflare; done
```
