<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Contracts;

use DateTimeImmutable;

/**
 * Contrato de fonte de publicações DJEN.
 *
 * Implementação default: `Services\DjenAdapter` (consome
 * comunicaapi.pje.jus.br/api/v1 via REST). Adapter pluggable permite trocar
 * fonte sem refatorar (NFR24 — AASP em Growth pode declarar
 * `AaspSourceAdapterContract` paralelo ou DjenAdapter evoluir para
 * Strategy pattern multi-source).
 *
 * Uso de `iterable` permite implementação via generator (memória constante
 * para datasets grandes — Comunica API paginação 100 itens/página + advogado
 * com 100+ pubs/dia → adapter pagina e yields row a row sem materializar).
 *
 * Schema do DTO retornado validado contra resposta real da Comunica API
 * (curl 2026-05-03, OAB 462034/SP, ver tests/fixtures/comunica-api-462034-SP-202604.json).
 */
interface PublicationSourceAdapterContract
{
    /**
     * Busca publicações para um advogado em uma janela de datas.
     *
     * Pagina automaticamente até esgotar (campo `count` da resposta vs items
     * já consumidos). Yields cada publicação como DTO normalizado.
     *
     * @param string $oab           Número OAB (apenas dígitos, sem máscara — ex.: "462034").
     * @param string $uf            UF do OAB (sigla 2 letras — ex.: "SP", "RJ").
     * @param DateTimeImmutable $dataInicio  Início da janela (inclusivo).
     * @param DateTimeImmutable $dataFim     Fim da janela (inclusivo).
     *
     * @return iterable<array{
     *     id: int,
     *     numeroProcesso: string,
     *     numeroProcessoComMascara: string,
     *     siglaTribunal: string,
     *     nomeOrgao: string,
     *     idOrgao: int,
     *     tipoComunicacao: string,
     *     tipoDocumento: string,
     *     dataDisponibilizacao: string,
     *     texto: string,
     *     link: string,
     *     meio: string,
     *     meioCompleto: string,
     *     codigoClasse: string,
     *     nomeClasse: string,
     *     numeroComunicacao: int,
     *     hash: string,
     *     status: string,
     *     ativo: bool,
     *     motivoCancelamento: ?string,
     *     dataCancelamento: ?string,
     *     destinatarios: list<array{nome:string, polo:string}>,
     *     destinatarioAdvogados: list<array{
     *         advogadoId: int,
     *         nome: string,
     *         numeroOab: string,
     *         ufOab: string
     *     }>
     * }>
     *
     * @throws \Espo\Modules\TogareDjen\Exception\DjenAdapterUnavailableException
     *         após esgotar retries OU com circuit breaker aberto.
     */
    public function fetchPublicacoes(
        string $oab,
        string $uf,
        DateTimeImmutable $dataInicio,
        DateTimeImmutable $dataFim,
    ): iterable;
}
