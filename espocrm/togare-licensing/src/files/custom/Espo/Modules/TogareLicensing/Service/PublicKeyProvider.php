<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

use RuntimeException;

/**
 * Carrega a chave pública RSA embutida do togare-licensing (PEM).
 *
 * A chave PÚBLICA é commitada no repositório (em Resources/keys/togare-public.pem)
 * por design — sua exposição não compromete segurança. A chave privada
 * correspondente fica APENAS no servidor Togare empresa e nunca aparece neste
 * repositório (NFR35).
 *
 * Em testes, o construtor aceita um path alternativo para a chave (fixture).
 */
final class PublicKeyProvider
{
    private const DEFAULT_RELATIVE_PATH = '/../Resources/keys/togare-public.pem';

    public function __construct(
        private readonly ?string $explicitPath = null,
    ) {
    }

    /**
     * Retorna o conteúdo PEM da chave pública.
     *
     * @throws RuntimeException se o arquivo não existir ou estiver vazio.
     */
    public function getPublicKeyPem(): string
    {
        $path = $this->explicitPath ?? \realpath(__DIR__ . self::DEFAULT_RELATIVE_PATH);

        if ($path === false || ! \is_file($path)) {
            throw new RuntimeException(
                'Chave pública RSA não encontrada (esperada em Resources/keys/togare-public.pem)',
            );
        }

        $contents = \file_get_contents($path);
        if ($contents === false || \trim($contents) === '') {
            throw new RuntimeException(
                'Chave pública RSA está vazia ou ilegível: ' . $path,
            );
        }

        // Normaliza CRLF → LF (lcobucci/jwt v5 rejeita PEM com \r\n).
        return \str_replace("\r\n", "\n", $contents);
    }
}
