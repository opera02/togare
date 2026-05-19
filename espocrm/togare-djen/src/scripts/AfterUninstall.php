<?php

use Espo\Core\Container;

/**
 * Called when the togare-djen extension is uninstalled.
 *
 * Por design no-op: tabela auxiliar `togare_djen_user_state` NÃO é removida
 * automaticamente (preserva last_synced_at em caso de reinstalação). Admin
 * pode rodar manualmente se quiser:
 *   DROP TABLE togare_djen_user_state;
 *
 * Items pendentes em `togare_queue_items` queue_name='djen' permanecem na
 * fila — o worker (togare-djen-worker) é parado pelo docker compose; sem
 * worker, items pending ficam ociosos até reinstalação ou intervenção
 * manual (ADR 0005 — fila é fonte da verdade).
 */
class AfterUninstall
{
    public function run(Container $container)
    {}
}
