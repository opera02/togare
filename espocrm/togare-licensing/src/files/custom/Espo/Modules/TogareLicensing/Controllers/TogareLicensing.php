<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Container;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Events\EventDispatcher;
use Espo\Modules\TogareLicensing\Service\JwtValidator;
use Espo\Modules\TogareLicensing\Service\LicenseKeyService;
use Espo\Modules\TogareLicensing\Service\PublicKeyProvider;
use Espo\ORM\EntityManager;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Endpoints REST do togare-licensing.
 *
 *   POST /api/v1/TogareLicensing/action/activateKey
 *     Body: {"key": "<jwt>"}
 *     Auth: admin only (Forbidden no construtor).
 *     200 success: {"success": true, "modulesActivated": [...], "expiresAt": "ISO8601"}
 *     400 invalid: {"success": false, "reason": "...", "message": "..."}
 */
class TogareLicensing
{
    public function __construct(
        private readonly Container $container,
        private readonly User $user,
    ) {
        if (! $this->user->isAdmin()) {
            throw new Forbidden('Apenas administradores podem ativar chaves de licença.');
        }
    }

    public function postActionActivateKey(Request $request): stdClass
    {
        $body = $request->getParsedBody();
        $key = \is_object($body) && isset($body->key) ? (string) $body->key : '';

        if ($key === '') {
            throw new BadRequest('Campo "key" é obrigatório no body.');
        }

        $service = $this->buildLicenseKeyService();
        $result = $service->activate($key);

        $response = new stdClass();
        $response->success = $result->success;

        if (! $result->success) {
            $response->reason = $result->reason;
            $response->message = $result->errorMessage;

            // EspoCRM retorna o stdClass diretamente; status 400 é setado via exceção.
            throw new BadRequest(\json_encode($response, \JSON_UNESCAPED_UNICODE) ?: 'Chave inválida');
        }

        $response->modulesActivated = $result->modulesActivated;
        $response->expiresAt = $result->expiresAt?->format(\DATE_ATOM);

        return $response;
    }

    private function buildLicenseKeyService(): LicenseKeyService
    {
        $entityManager = $this->container->getByClass(EntityManager::class);
        $pdo = $entityManager->getPDO();

        $clock = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };

        $jwtValidator = new JwtValidator(
            new PublicKeyProvider(),
            $clock,
        );

        /** @var EventDispatcher $eventBus */
        $eventBus = $this->container->get('togareCoreEventDispatcher');

        return new LicenseKeyService($jwtValidator, $pdo, $eventBus);
    }
}
