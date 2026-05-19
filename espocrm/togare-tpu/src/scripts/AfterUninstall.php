<?php

use Espo\Core\Container;

/**
 * Called when the togare-tpu extension is uninstalled.
 *
 * Por design no-op: tabelas de catálogo TPU não são removidas automaticamente.
 * Admin pode optar por preservá-las (lookup histórico) ou rodar manualmente:
 *   DROP TABLE togare_tpu_classe, togare_tpu_assunto, togare_tpu_movimento;
 * Cache Redis com TTL 35d auto-expira.
 */
class AfterUninstall
{
    public function run(Container $container)
    {}
}
