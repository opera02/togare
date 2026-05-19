<div class="togare-doc-upload">
    {{#if contextLabel}}
        <p class="togare-doc-upload-context">
            <strong>{{translate "contextLabel" category="labels" scope="Documento"}}:</strong>
            {{contextLabel}}
        </p>
    {{/if}}

    <div class="form-group">
        <label for="togareDocumentoFile">
            {{translate "chooseFile" category="labels" scope="Documento"}}
        </label>
        <input
            type="file"
            id="togareDocumentoFile"
            name="togareDocumentoFile"
            accept="{{acceptAttribute}}"
            class="form-control"
        />
        <p class="togare-doc-upload-preview" aria-live="polite"></p>
        <p class="togare-doc-upload-hint">
            {{translate "uploadHint" category="messages" scope="Documento"}}
        </p>
    </div>
</div>
