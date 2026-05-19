<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-family: Arial, sans-serif; color: #2c3e50; max-width: 600px; margin: 0 auto;">
    <tr>
        <td style="padding: 24px 0; font-size: 18px; line-height: 1.6;">
            <p style="margin: 0 0 16px 0;">Olá{{#if firstName}}, {{firstName}}{{/if}}.</p>

            <p style="margin: 0 0 16px 0;">
                Você foi convidado para usar o sistema do escritório no <strong>Togare</strong>.
                Para começar, crie sua senha clicando no botão abaixo:
            </p>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 24px 0;">
                <tr>
                    <td style="background: #2c5282; border-radius: 6px;">
                        <a href="{{siteUrl}}"
                           style="display: inline-block; padding: 14px 28px; color: #ffffff; font-size: 16px; font-weight: 600; text-decoration: none;">
                            Criar minha senha
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin: 0 0 16px 0; font-size: 14px; color: #718096;">
                Ou copie e cole este endereço no navegador:<br/>
                <a href="{{siteUrl}}" style="color: #2c5282;">{{siteUrl}}</a>
            </p>

            <p style="margin: 24px 0 16px 0; padding: 12px 16px; background: #fffbea; border-left: 4px solid #d69e2e; font-size: 14px;">
                Este link é válido por <strong>7 dias</strong>. Depois disso, peça ao administrador um novo convite.
            </p>

            <p style="margin: 24px 0 0 0; font-size: 13px; color: #a0aec0;">
                Seu nome de usuário no sistema é <strong>{{userName}}</strong>.<br/>
                Se você não estava esperando este convite, ignore esta mensagem.
            </p>
        </td>
    </tr>
</table>
