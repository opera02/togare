<div class="togare-wizard" role="dialog" aria-modal="true" aria-labelledby="togare-wizard-title">
    <div class="togare-wizard__overlay"></div>
    <div class="togare-wizard__shell">
        <header class="togare-wizard__header">
            <h1 id="togare-wizard-title" class="togare-wizard__title">
                {{stepLabel}}
            </h1>
            <p class="togare-wizard__progress" aria-live="polite">
                Passo {{currentStep}} de 4
            </p>
        </header>

        <main class="togare-wizard__body">
            {{#if isStep1}}
            <section class="togare-wizard__step togare-wizard__step--org">
                <label class="togare-wizard__label" for="togare-wizard-company-name">
                    Nome do escritório
                </label>
                <input id="togare-wizard-company-name"
                       data-role="company-name"
                       class="togare-wizard__input"
                       type="text"
                       value="{{state.companyName}}"
                       placeholder="Ex.: Escritório Smoke Ltda"
                       required>

                <label class="togare-wizard__label" for="togare-wizard-logo-id">
                    Logotipo (Attachment ID)
                </label>
                <input id="togare-wizard-logo-id"
                       data-role="company-logo-file-id"
                       class="togare-wizard__input"
                       type="text"
                       value="{{state.companyLogoFileId}}"
                       placeholder="Faça upload em /api/v1/Attachment e cole o ID aqui">
                <p class="togare-wizard__hint">
                    Recomendado: 256x256 px, fundo transparente. PNG, JPG ou SVG, até 512KB.
                </p>
            </section>
            {{/if}}

            {{#if isStep2}}
            <section class="togare-wizard__step togare-wizard__step--color">
                <p class="togare-wizard__hint">Cor primária (#RRGGBB) — escolha uma sugerida ou digite o hex:</p>
                <div class="togare-wizard__swatches">
                    {{#each suggestedColors}}
                    {{!-- P3: sem inline style; cor via CSS custom property --togare-primary definida pelo JS. --}}
                    <button type="button"
                            class="togare-wizard__swatch"
                            data-role="color-swatch"
                            data-color="{{this}}"
                            aria-label="Cor {{this}}"></button>
                    {{/each}}
                </div>

                <label class="togare-wizard__label" for="togare-wizard-color-hex">
                    Hex personalizado (#RRGGBB)
                </label>
                <input id="togare-wizard-color-hex"
                       data-role="color-input"
                       class="togare-wizard__input"
                       type="text"
                       value="{{state.primaryColor}}"
                       maxlength="7"
                       placeholder="#0a4d8c">

                {{!-- P3: preview usa CSS var --togare-primary; sem interpolação inline. --}}
                <div class="togare-wizard__color-preview togare-wizard__color-preview--css-var"
                     aria-label="Pré-visualização da cor primária."></div>
            </section>
            {{/if}}

            {{#if isStep3}}
            <section class="togare-wizard__step togare-wizard__step--roles">
                <p class="togare-wizard__hint">
                    Os 8 roles vêm pré-configurados. Você pode renomear (exceto
                    "Sócio/Admin", reservado pela lógica de MFA).
                </p>
                <table class="togare-wizard__roles-table">
                    <thead>
                        <tr><th>Role atual</th><th>Renomear (opcional)</th></tr>
                    </thead>
                    <tbody>
                        {{#each state.roles}}
                        <tr>
                            <td>{{this.name}}</td>
                            <td>
                                {{#if this.isReserved}}
                                <em>Reservado</em>
                                {{else}}
                                <input type="text"
                                       data-role="role-rename"
                                       data-old-name="{{this.name}}"
                                       class="togare-wizard__input togare-wizard__input--inline"
                                       value="{{this.name}}">
                                {{/if}}
                            </td>
                        </tr>
                        {{/each}}
                    </tbody>
                </table>
            </section>
            {{/if}}

            {{#if isStep4}}
            <section class="togare-wizard__step togare-wizard__step--invite">
                <p class="togare-wizard__hint">
                    Adicione até 20 usuários. Cada um receberá um e-mail com link
                    de ativação para criar a senha. Deixar em branco para pular convites.
                </p>
                <table class="togare-wizard__invite-table">
                    <thead>
                        <tr>
                            <th>userName</th>
                            <th>E-mail</th>
                            <th>Nome</th>
                            <th>Sobrenome</th>
                            <th>Role IDs (vírgula-separados)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{!-- P16: value vinculado ao state para preservar dados no reRender. --}}
                        {{#each state.invitees}}
                        <tr data-role="invitee-row">
                            <td><input class="togare-wizard__input togare-wizard__input--inline" type="text" name="userName" value="{{this.userName}}"></td>
                            <td><input class="togare-wizard__input togare-wizard__input--inline" type="email" name="emailAddress" value="{{this.emailAddress}}"></td>
                            <td><input class="togare-wizard__input togare-wizard__input--inline" type="text" name="firstName" value="{{this.firstName}}"></td>
                            <td><input class="togare-wizard__input togare-wizard__input--inline" type="text" name="lastName" value="{{this.lastName}}"></td>
                            <td><input class="togare-wizard__input togare-wizard__input--inline" type="text" name="roleIds" placeholder="role-id-1,role-id-2" value="{{this.roleIdsStr}}"></td>
                        </tr>
                        {{/each}}
                    </tbody>
                </table>
                <button type="button"
                        class="btn btn-default"
                        data-action="invite-add-row">+ Adicionar usuário</button>
            </section>
            {{/if}}

            <div data-role="skip-confirm-mount"></div>
        </main>

        <footer class="togare-wizard__footer">
            <button type="button"
                    class="btn btn-link togare-wizard__skip"
                    data-action="skip">
                Pular wizard
            </button>
            <div class="togare-wizard__nav">
                {{#unless isStep1}}
                <button type="button" class="btn btn-default" data-action="prev">← Anterior</button>
                {{/unless}}
                {{#if isStep4}}
                <button type="button" class="btn btn-primary" data-action="finish">Concluir wizard</button>
                {{else}}
                <button type="button" class="btn btn-primary" data-action="next">Próximo →</button>
                {{/if}}
            </div>
        </footer>

        {{!-- P20: hedge-banner montado como view real via afterRender() em wizard-shell.js. --}}
        <div data-role="hedge-banner-mount"></div>
    </div>
</div>
