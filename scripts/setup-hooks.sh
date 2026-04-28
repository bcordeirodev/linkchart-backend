#!/usr/bin/env bash
#
# Link Charts — ativa hooks Git versionados (scripts/hooks).
# Rode uma vez após clonar o repo (ou após trocar de máquina).

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
HOOKS_DIR="scripts/hooks"

cd "$REPO_DIR"

if [ ! -d "$HOOKS_DIR" ]; then
	echo "❌ Diretório $HOOKS_DIR não existe em $REPO_DIR"
	exit 1
fi

# Garante que todos os hooks são executáveis
chmod +x "$HOOKS_DIR"/* 2>/dev/null || true

# Aponta o Git para os hooks versionados
git config core.hooksPath "$HOOKS_DIR"

echo "✅ Hooks ativados — git core.hooksPath = $HOOKS_DIR"
echo "   Para desativar temporariamente um push:  git push --no-verify"
echo "   Para desativar de vez:                   git config --unset core.hooksPath"
