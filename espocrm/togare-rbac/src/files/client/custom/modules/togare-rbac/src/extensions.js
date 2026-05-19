/**
 * Togare RBAC — extensions front-end (Story 2.6).
 *
 * Trigger frontend-only do wizard pós-primeiro-login. Ao boot do EspoCRM:
 * 1. Lê appParams.togareWizardRequired
 * 2. Se true e wizard não está aberto → monta WizardShellView como modal full-screen.
 *
 * P10: usa app.once (não app.on) + desregistra handlers após mount bem-sucedido.
 * P30: setTimeout com limite de 50 tentativas (~5s) para não lopar infinitamente.
 */

(function () {
    'use strict';

    if (typeof Espo === 'undefined') {
        return;
    }

    var TOGARE_WIZARD_OPENED = false;

    var openWizard = function () {
        if (TOGARE_WIZARD_OPENED) {
            return;
        }
        var app = Espo.app;
        if (!app) {
            return;
        }
        var appParams = app.appParams || {};
        if (appParams.togareWizardRequired !== true) {
            return;
        }

        if (!app.viewFactory || !app.viewFactory.create) {
            return;
        }

        TOGARE_WIZARD_OPENED = true;

        try {
            // P10: remover listeners após mount — evita re-disparo em rerenders.
            if (app.off) {
                app.off('after:auth', openWizard);
                app.off('after:render', openWizard);
            }

            app.viewFactory.create(
                'togare-rbac:wizard/wizard-shell',
                {
                    el: '#togare-rbac-wizard-mount',
                },
                function (view) {
                    var mount = document.getElementById('togare-rbac-wizard-mount');
                    if (!mount) {
                        mount = document.createElement('div');
                        mount.id = 'togare-rbac-wizard-mount';
                        document.body.appendChild(mount);
                    }
                    view.render();
                }
            );
        } catch (e) {
            TOGARE_WIZARD_OPENED = false;
            if (typeof console !== 'undefined' && console.error) {
                console.error('[togare-rbac wizard] failed to mount:', e);
            }
        }
    };

    // P30: contador para evitar loop infinito se Espo.app nunca aparecer.
    // Timeout 30s (300 × 100ms) — Espo.app só nasce após auth XHR completar,
    // e em prod com assets pesados pode levar mais que 5s. Manter conservador.
    var onReadyAttempts = 0;
    var MAX_READY_ATTEMPTS = 300;

    var onReady = function () {
        var app = Espo.app;
        if (!app) {
            onReadyAttempts++;
            if (onReadyAttempts < MAX_READY_ATTEMPTS) {
                setTimeout(onReady, 100);
            } else if (typeof console !== 'undefined' && console.info) {
                console.info('[togare-rbac wizard] Espo.app indisponível após 30s; wizard não será montado nesta sessão (esperado se user não atende critérios).');
            }
            return;
        }

        // P10: usar once em vez de on para auto-desregistro.
        if (app.once) {
            app.once('after:auth', openWizard);
            app.once('after:render', openWizard);
        } else if (app.on) {
            app.on('after:auth', openWizard);
            app.on('after:render', openWizard);
        }

        // Best-effort imediato (caso boot já tenha completado).
        setTimeout(openWizard, 200);
    };

    if (typeof document !== 'undefined' && document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
}());
