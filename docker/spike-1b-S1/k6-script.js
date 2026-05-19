// k6 bench script — PREPARADO na Fase 1 (Story 1b.S1), EXECUTADO na Fase 2
// (Epic 10, story 10.X-bench-nfr a criar).
//
// NÃO EXECUTAR este script no laptop Windows + Docker Desktop + WSL2 — overhead
// de virtio-fs invalida qualquer medida de NFR1. Só rodar em VPS baseline
// (4vCPU/8GB/SSD NVMe Ubuntu 22.04 LTS) com a mesma stack spike de pé no host.
//
// Cenário: 10 VUs concorrentes × 5 min × PDF 200 MB, endpoints:
//   - /api/v1/Spike/action/download?path=test-200mb.pdf           (X-Accel via Caddy)
//   - /api/v1/Spike/action/download?path=test-200mb.pdf&use_proxy=php (PHP-proxy)
//
// Thresholds declaram NFR1: p95 TTFB ≤ 2s; p95 total ≤ 15s no X-Accel.
// PHP-proxy mede como referência — sem threshold duro (comparativo).
//
// USO (na Fase 2, dentro do VPS):
//   k6 run --env TARGET=xaccel docker/spike-1b-S1/k6-script.js
//   k6 run --env TARGET=phpproxy docker/spike-1b-S1/k6-script.js
//
// DOCS: https://k6.io/docs/using-k6/thresholds/

import http from "k6/http";
import { check } from "k6";

const TARGET = (__ENV.TARGET || "xaccel").toLowerCase();
// Trailing slash removido para evitar double slash na URL construída abaixo.
const BASE_URL = (__ENV.BASE_URL || "https://localhost:8443").replace(/\/$/, "");

const queryByTarget = {
  xaccel: "path=test-200mb.pdf",
  phpproxy: "path=test-200mb.pdf&use_proxy=php",
};

if (!queryByTarget[TARGET]) {
  throw new Error(`TARGET inválido: "${TARGET}". Use "xaccel" ou "phpproxy".`);
}

const URL = `${BASE_URL}/api/v1/Spike/action/download?${queryByTarget[TARGET]}`;

export const options = {
  scenarios: {
    // Warm-up: 2 VUs × 2 requests para inicializar o pool FPM e o pipeline Caddy
    // antes das medições oficiais. Sem warm-up, o p95 inclui overhead de JIT/init
    // que não reflete o comportamento em steady state.
    warmup: {
      executor: "shared-iterations",
      vus: 2,
      iterations: 2,
      maxDuration: "90s",
      gracefulStop: "0s",
      tags: { phase: "warmup" },
    },
    // Benchmark principal: 10 VUs concorrentes × 5 min — cenário NFR1 do PRD.
    // Começa após 90s de warm-up.
    download_200mb: {
      executor: "constant-vus",
      vus: 10,
      duration: "5m",
      startTime: "90s",
      tags: { phase: "bench" },
    },
  },
  // Thresholds aplicam APENAS às requests da fase bench (tag phase=bench).
  // Thresholds duros apenas no X-Accel (caminho primário do ADR 0004).
  // PHP-proxy é comparativo — registrar métricas mas não falhar o bench.
  thresholds:
    TARGET === "xaccel"
      ? {
          "http_req_waiting{phase:bench}": ["p(95)<2000"], // TTFB p95 ≤ 2s (NFR1)
          "http_req_duration{phase:bench}": ["p(95)<15000"], // Total p95 ≤ 15s
          "http_req_failed{phase:bench}": ["rate<0.01"], // <1% falhas
        }
      : {
          "http_req_failed{phase:bench}": ["rate<0.05"], // tolerância maior no fallback
        },
  // Aceita cert auto-assinado do `tls internal` do Caddy spike.
  // ATENÇÃO: não apontar BASE_URL para ambiente de produção com este flag ativo.
  insecureSkipTLSVerify: true,
};

export default function () {
  const res = http.get(URL, {
    tags: { target: TARGET },
    // Timeout explícito: evita que VUs presos em conexões travadas sejam
    // contabilizados erroneamente (k6 default é 60s — muito alto para detectar
    // servidores lentos dentro da janela de 5min do bench).
    timeout: "30s",
    // responseType 'none' evita carregar o body de 200MB na memória do k6.
    // Com 10 VUs simultâneos, bufferizar o body resultaria em ~2GB de RAM
    // consumida pelo k6 — potencial OOM no VPS baseline de 8GB.
    // Integridade verificada via Content-Length header (servidor declara o tamanho).
    responseType: "none",
  });

  check(res, {
    "status 200": (r) => r.status === 200,
    // Verifica tamanho pelo header Content-Length (sem bufferizar body).
    "tamanho ~200MB": (r) => {
      const cl = parseInt(r.headers["Content-Length"] || "0", 10);
      return cl >= 209715200 * 0.98 && cl <= 209715200 * 1.02;
    },
  });
}
