<div class="togare-confirmacao-textual{{#if modal}} togare-confirmacao-textual--modal{{/if}}"
    {{#if modal}}role="dialog" aria-modal="true"{{/if}}>
    <form class="togare-confirmacao-textual__form">
        {{#if destructiveWarning}}
            <aside class="togare-hedge-banner togare-hedge-banner--action-destructive"
                   role="note">
                <span class="togare-hedge-banner__icon" aria-hidden="true">⚠</span>
                <span class="togare-hedge-banner__text">{{ariaDestructiveWarning}}</span>
            </aside>
        {{/if}}

        <label class="togare-confirmacao-textual__label"
               for="togare-confirmacao-input">
            {{instrucao}}
        </label>

        <input type="text"
               id="togare-confirmacao-input"
               class="togare-confirmacao-textual__input"
               data-role="confirmacao-input"
               placeholder="{{placeholder}}"
               aria-describedby="togare-confirmacao-desc"
               autocomplete="off"
               spellcheck="false">

        <p id="togare-confirmacao-desc" class="togare-confirmacao-textual__desc">
            {{ariaDescription}}
        </p>

        <div class="togare-confirmacao-textual__actions">
            <button type="button"
                    class="togare-confirmacao-textual__cancel btn btn-default"
                    data-action="cancel">
                {{cancelLabel}}
            </button>
            <button type="submit"
                    class="togare-confirmacao-textual__cta btn btn-danger"
                    data-action="confirm"
                    {{#if ctaDisabled}}disabled{{/if}}>
                {{ctaLabel}}
            </button>
        </div>
    </form>
</div>
