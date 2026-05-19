<aside class="{{cssClass}}" data-variant="{{variant}}" role="alert">
    <span class="togare-gate-banner__icon" role="img" aria-label="Atenção">⚠️</span>
    <span class="togare-gate-banner__text">{{text}}</span>
    {{#if ctaLabel}}
        <button type="button" class="btn btn-sm btn-warning togare-gate-banner__cta"
                data-cta-target="{{ctaTarget}}">{{ctaLabel}}</button>
    {{/if}}
</aside>
