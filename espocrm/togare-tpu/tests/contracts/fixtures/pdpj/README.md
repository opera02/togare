# Fixtures PDPJ — togare-tpu (Story 3.3)

## Origem

Amostras representativas do gateway oficial CNJ:
`https://gateway.cloud.pje.jus.br/tpu/{classes,assuntos,movimentos}`.

Capturadas manualmente em **2026-04-21** (data do distillate). Estrutura
espelha o JSON real da API.

- `classes-success-sample.json` — 51 rows (Cíveis, Trabalhistas, Penais, Recursos).
- `assuntos-success-sample.json` — 35 rows (Civil, Adm, Trib, Trab, Penal).
- `movimentos-success-sample.json` — 32 rows (movimentos cíveis/penais).

## Quando atualizar

Atualizar quando bater fixture against API real falhar repetidamente
(ex.: schema mudou). Bump da fixture é PR separado:
`refactor(tpu): bump fixtures pdpj — schema X mudou em data Y`.

## Em CI

Zero chamada à rede real. Adapter usa
`TOGARE_TPU_BASE_URL=file://path/to/fixture.json` (file scheme do PHP stream
wrapper). Ver Dev Notes §5 da Story 3.3.

## Fixtures de erro

- `classes-empty.json` — array vazio (válido — sync com 0 rows).
- `classes-malformed.json` — objeto em vez de array (deve disparar
  `TpuAdapterUnavailableException` no PdpjAdapter).
