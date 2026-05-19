#!/usr/bin/env bash
# Hook: bloqueia commit de arquivos sensíveis (.env, *.pem, *.key, chaves SSH).
# Permite *.env.example.

set -u

if [ "$#" -eq 0 ]; then
  exit 0
fi

blocked=""

for f in "$@"; do
  # Permitir *.env.example
  case "$f" in
    *.env.example) continue ;;
  esac

  # Allowlist de PEMs commitados intencionalmente (chaves públicas e fixtures
  # de teste com cabeçalho de warning). Story 1b.1 togare-licensing.
  case "$f" in
    espocrm/togare-licensing/src/files/custom/Espo/Modules/TogareLicensing/Resources/keys/togare-public.pem) continue ;;
    espocrm/togare-licensing/tests/fixtures/togare-public-test.pem) continue ;;
    espocrm/togare-licensing/tests/fixtures/togare-private-test.pem) continue ;;
  esac

  # Bloquear extensões sensíveis
  case "$f" in
    *.env|.env|*.pem|*.key|*id_rsa|*id_ed25519)
      blocked="$blocked\n  - $f"
      ;;
  esac
done

if [ -n "$blocked" ]; then
  echo "❌ Tentativa de commit de arquivo sensível:"
  printf "$blocked\n"
  echo ""
  echo "   Se for proposital, remover do stage (git restore --staged <arquivo>)"
  echo "   ou ajustar tools/hooks/block-secrets.sh."
  exit 1
fi

exit 0
