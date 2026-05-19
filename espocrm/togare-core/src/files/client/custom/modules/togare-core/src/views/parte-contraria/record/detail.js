/**
 * ParteContraria record/detail view (Story 3.2).
 *
 * Estende `views/record/detail.js` nativo. Mínima — usa
 * layouts/ParteContraria/detail.json e o Stream nativo do EspoCRM (declarado
 * via `"stream": true` em `Resources/metadata/scopes/ParteContraria.json`).
 *
 * Helpers Handlebars `formatCpf/Cnpj/Phone` já registrados globalmente via
 * `js/bootstrap-formatters.js` (carregado por `metadata/app/client.json`
 * scriptList) — disponíveis em qualquer template sem import.
 *
 * Painel "Processos" será renderizado vazio enquanto Story 3.4 não declarar
 * o lado reverso `partesContrarias` em entityDefs/Processo.json — comportamento
 * esperado, sem erro JS (Dev Notes §5 da story).
 */
import DetailView from 'views/record/detail';

export default class ParteContrariaDetailView extends DetailView {
    setup() {
        super.setup();
    }
}
