# Guia de Deploy — Link Chart

> **Este arquivo é um stub.** O guia canônico de deploy vive na raiz do workspace:
> **[`../../docs/DEPLOY.md`](../../docs/DEPLOY.md)** (fora deste repo Git — no diretório `link-charts/docs/` que engloba backend e frontend).

Resumo de 10 segundos:

```bash
git tag v2.x.y && git push origin v2.x.y            # ← ISTO deploya (merge em main NÃO)
gh workflow run "Release (backend)" -f ref=v2.x.w   # rollback / deploy manual
```

O canônico cobre: CI/CD por tag `v*`, build no runner do GitHub + GHCR, cutover blue/green com zero downtime, as três stacks Compose (`infra`/`app`/`worker`), migrations expand/contract e rollback.

Este stub existia como cópia byte-idêntica do canônico; foi reduzido em 2026-07-27 para eliminar a duplicação — edite sempre o arquivo da raiz.
