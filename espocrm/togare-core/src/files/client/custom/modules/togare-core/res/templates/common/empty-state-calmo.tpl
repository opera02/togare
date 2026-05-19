<div class="{{cssClass}}" role="status" data-variant="{{variant}}" data-context="{{context}}">
    <p class="togare-empty-state-calmo__text">{{text}}</p>
    {{#if ctaLabel}}
        <button type="button" class="togare-empty-state-calmo__cta btn btn-link" data-action="togare-empty-cta">
            {{ctaLabel}}
        </button>
    {{/if}}
</div>
