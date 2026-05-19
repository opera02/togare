<div class="page-header">
    <h3>{{title}}</h3>
</div>

<div class="panel panel-default">
    <div class="panel-body">
        <p class="text-muted">{{description}}</p>

        <div class="alert alert-warning" style="margin-top: 16px;">
            <strong>{{warningTitle}}</strong>
            <p style="margin: 4px 0 0 0;">{{warningText}}</p>
        </div>

        <div class="form-group" style="margin-top: 16px;">
            <textarea
                name="jwtKey"
                class="form-control"
                rows="6"
                placeholder="{{placeholder}}"
                style="font-family: monospace; font-size: 12px;"></textarea>
        </div>

        <button
            type="button"
            class="btn btn-primary"
            data-action="activate"
            style="margin-top: 8px;">
            {{buttonLabel}}
        </button>
    </div>
</div>
