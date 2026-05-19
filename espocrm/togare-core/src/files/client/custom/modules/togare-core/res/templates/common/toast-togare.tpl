<div class="{{cssClass}}" role="{{role}}" data-id="{{id}}" data-variant="{{variant}}">
    <span class="togare-toast__icon" aria-hidden="true">{{icon}}</span>
    <span class="togare-toast__message">{{message}}</span>
    {{#if actionLabel}}
        <button type="button" class="togare-toast__action btn btn-link" data-action="toast-do">
            {{actionLabel}}
        </button>
    {{/if}}
    <button type="button" class="togare-toast__close" data-action="toast-close" aria-label="Fechar">
        &times;
    </button>
    {{#if showProgress}}
        <span class="togare-toast__progress" aria-hidden="true" style="animation-duration: {{durationMs}}ms"></span>
    {{/if}}
</div>
