<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Entities\Role;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\HealthCheckService;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Story 10.1 — BriefingDoDia com 7 configs role-aware (MVP piloto interno).
 *
 * Monta o payload do `GET /api/v1/TogareBriefing/action/data`:
 *   { badges: [...], role: string, generatedAt: ISO8601 }
 *
 * Regras vinculantes:
 *  - **Blindagem cruzada (AC2):** cada método privado por role retorna
 *    SOMENTE as contagens do próprio role. Role desconhecido → array vazio (AC3).
 *  - **Nunca lança (AC6):** todo método privado é envolto em try/catch.
 *  - **Reuso HealthCheckService (AC1 Sócio/Admin):** NÃO recriar a lógica de
 *    health; derivar o summary status a partir do getPanel() já implementado.
 *  - **Roles do piloto:** Sócio/Admin, Advogado, Assistente, Secretária,
 *    Financeiro, Marketing, RH-lite. Outros → array vazio calmo.
 */
final class TogareBriefingService
{
    private const ROLE_SOCIO_ADMIN = 'Sócio/Admin';
    private const ROLE_ADVOGADO    = 'Advogado';
    private const ROLE_ASSISTENTE  = 'Assistente';
    private const ROLE_SECRETARIA  = 'Secretária';
    private const ROLE_FINANCEIRO  = 'Financeiro';
    private const ROLE_MARKETING   = 'Marketing';
    private const ROLE_RH_LITE     = 'RH-lite';

    /** Mapa nome-do-role → chave de config JSON (e método interno). */
    private const ROLE_MAP = [
        self::ROLE_SOCIO_ADMIN => 'socio-admin',
        self::ROLE_ADVOGADO    => 'advogado',
        self::ROLE_ASSISTENTE  => 'assistente',
        self::ROLE_SECRETARIA  => 'secretaria',
        self::ROLE_FINANCEIRO  => 'financeiro',
        self::ROLE_MARKETING   => 'marketing',
        self::ROLE_RH_LITE     => 'rh-lite',
    ];

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly HealthCheckService $healthCheckService,
    ) {
    }

    /**
     * Payload completo do painel por role.
     *
     * @param User $user  Usuário autenticado (injetado via DI no Controller).
     * @return array{badges: list<array>, role: string, generatedAt: string}
     */
    public function getSummaryForUser(User $user): array
    {
        $roleName = $this->resolveRoleName($user);
        $roleKey  = self::ROLE_MAP[$roleName] ?? '';

        $badges = match ($roleKey) {
            'socio-admin' => $this->badgesForSocioAdmin(),
            'advogado'    => $this->badgesForAdvogado($user),
            'assistente'  => $this->badgesForAssistente($user),
            'secretaria'  => $this->badgesForSecretaria(),
            'financeiro'  => $this->badgesForFinanceiro(),
            'marketing'   => $this->badgesForMarketing(),
            'rh-lite'     => $this->badgesForRhLite(),
            default       => [],
        };

        return [
            'badges'      => $badges,
            'role'        => $roleKey,
            'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
        ];
    }

    // ── Role resolution ────────────────────────────────────────────────────

    private function resolveRoleName(User $user): string
    {
        if ($user->isAdmin()) {
            return self::ROLE_SOCIO_ADMIN;
        }

        try {
            $roleIds = $user->getLinkMultipleIdList('roles');
            foreach ($roleIds as $roleId) {
                $role = $this->entityManager->getEntityById(Role::ENTITY_TYPE, $roleId);
                if ($role instanceof Role) {
                    $name = (string) $role->get('name');
                    if (isset(self::ROLE_MAP[$name])) {
                        return $name;
                    }
                }
            }
        } catch (Throwable) {
            // Não consegue resolver roles → retorna '' → badges vazio (AC2/AC3).
        }

        return '';
    }

    // ── Por role ───────────────────────────────────────────────────────────

    private function badgesForSocioAdmin(): array
    {
        $badges = [];

        // Health summary (reuso HealthCheckService — NÃO recriar lógica AC1).
        try {
            $panel = $this->healthCheckService->getPanel();
            $tiles = $panel['tiles'] ?? [];
            // P14: infra-core (mariadb/redis/backup) determina severidade;
            // integrações opcionais (djen/tpu/nextcloud) só elevam para 'lento'.
            $infraKeys   = ['mariadb', 'redis', 'backup'];
            $coreAlerts  = 0;
            $optAlerts   = 0;
            foreach ($tiles as $tile) {
                $tileState = $tile['state'] ?? '';
                $tileKey   = $tile['key'] ?? '';
                if ($tileState !== 'ok' && $tileState !== 'nao_instalado') {
                    if (\in_array($tileKey, $infraKeys, true)) {
                        $coreAlerts++;
                    } else {
                        $optAlerts++;
                    }
                }
            }
            $healthStatus = match (true) {
                $coreAlerts >= 1 => 'offline',
                $optAlerts >= 1  => 'lento',
                default          => 'ok',
            };
            $badges[] = [
                'key'          => 'health',
                'title'        => 'Saúde do sistema',
                'count'        => null,
                'healthStatus' => $healthStatus,
                'alertCount'   => $coreAlerts + $optAlerts,
                'cta'          => 'Ver painel completo',
                'link'         => '#',
                'type'         => 'health',
            ];
        } catch (Throwable) {
            $badges[] = [
                'key'          => 'health',
                'title'        => 'Saúde do sistema',
                'count'        => null,
                'healthStatus' => 'offline',
                'alertCount'   => 0,
                'cta'          => 'Ver painel completo',
                'link'         => '#',
                'type'         => 'health',
            ];
        }

        // Usuários ativos (equipe — apenas regular + admin; exclui portal/api/system).
        try {
            $userCount = $this->entityManager
                ->getRDBRepository('User')
                ->where([
                    'isActive' => true,
                    'type'     => ['regular', 'admin'],
                    'deleted'  => false,
                ])
                ->count();
            $badges[] = [
                'key'   => 'usuarios-ativos',
                'title' => 'Usuários ativos',
                'count' => $userCount,
                'cta'   => 'Ver equipe',
                'link'  => '#User/list',
            ];
        } catch (Throwable) {
        }

        // Licença (best-effort — omite se módulo togare-licensing ausente).
        try {
            if (\class_exists('Espo\\Modules\\TogareLicensing\\Service\\JwtValidator', true)) {
                $record = $this->entityManager
                    ->getRDBRepository('ModuleStatus')
                    ->order('expiresAt', 'ASC')
                    ->findOne();
                if ($record !== null) {
                    $expiresAt = $record->get('expiresAt');
                    if (\is_string($expiresAt) && $expiresAt !== '') {
                        $soonest = \strtotime($expiresAt);
                        if ($soonest !== false) {
                            $dayDiff = (int) \floor(($soonest - \time()) / 86400);
                            $status  = $dayDiff < 0 ? 'vencida' : ($dayDiff <= 30 ? 'expirando' : 'valida');
                            $badges[] = [
                                'key'           => 'licenca',
                                'title'         => 'Licença',
                                'count'         => null,
                                'licencaStatus' => $status,
                                'dayDiff'       => $dayDiff,
                                'cta'           => 'Ver status',
                                'link'          => '#Admin/ModuleStatus',
                            ];
                        }
                    }
                }
            }
        } catch (Throwable) {
        }

        return $badges;
    }

    private function badgesForAdvogado(User $user): array
    {
        $badges = [];
        $userId = (string) $user->getId();

        // Prazos pendentes (assignedUser = self).
        try {
            $count = $this->entityManager
                ->getRDBRepository('Prazo')
                ->where([
                    'assignedUserId' => $userId,
                    'status'         => 'pendente',
                    'deleted'        => false,
                ])
                ->count();
            $badges[] = [
                'key'   => 'prazo-pendente',
                'title' => 'Prazos pendentes',
                'count' => $count,
                'cta'   => 'Confirme hoje',
                'link'  => '#Prazo/list',
            ];
        } catch (Throwable) {
        }

        // Publicações para revisar (P1: filtrar por assignedUserId — AC2).
        try {
            $count = $this->entityManager
                ->getRDBRepository('PublicacaoAmbigua')
                ->where(['assignedUserId' => $userId, 'status' => 'pendente_revisao', 'deleted' => false])
                ->count();
            $badges[] = [
                'key'   => 'publicacao-nova',
                'title' => 'Publicações para revisar',
                'count' => $count,
                'cta'   => 'Leia estas',
                'link'  => '#PublicacaoAmbigua/list',
            ];
        } catch (Throwable) {
        }

        // Audiências esta semana.
        try {
            $count = $this->countAudienciasSemana();
            $badges[] = [
                'key'   => 'audiencia-semana',
                'title' => 'Audiências esta semana',
                'count' => $count,
                'cta'   => 'Ver agenda',
                'link'  => '#Audiencia/list',
            ];
        } catch (Throwable) {
        }

        return $badges;
    }

    private function badgesForAssistente(User $user): array
    {
        $badges = [];
        $userId = (string) $user->getId();

        // Tarefas atribuídas (P5: excluir concluídas/canceladas — só actionable).
        try {
            $count = $this->entityManager
                ->getRDBRepository('Task')
                ->where([
                    'assignedUserId' => $userId,
                    'status!='       => ['Completed', 'Cancelled'],
                    'deleted'        => false,
                ])
                ->count();
            $badges[] = [
                'key'   => 'tarefa-atribuida',
                'title' => 'Tarefas atribuídas',
                'count' => $count,
                'cta'   => 'Revise',
                'link'  => '#Task/list',
            ];
        } catch (Throwable) {
        }

        // Audiências esta semana.
        try {
            $count = $this->countAudienciasSemana();
            $badges[] = [
                'key'   => 'audiencia-semana',
                'title' => 'Audiências esta semana',
                'count' => $count,
                'cta'   => 'Ver agenda',
                'link'  => '#Audiencia/list',
            ];
        } catch (Throwable) {
        }

        return $badges;
    }

    private function badgesForSecretaria(): array
    {
        $badges = [];

        // Audiências hoje.
        try {
            $count = $this->countAudienciasHoje();
            $badges[] = [
                'key'   => 'audiencia-hoje',
                'title' => 'Audiências hoje',
                'count' => $count,
                'cta'   => 'Ver agenda',
                'link'  => '#Audiencia/list',
            ];
        } catch (Throwable) {
        }

        // Clientes (recentes = total — link leva para lista ordenada por data).
        try {
            $count = $this->entityManager
                ->getRDBRepository('Cliente')
                ->where(['deleted' => false])
                ->count();
            $badges[] = [
                'key'   => 'cliente-recente',
                'title' => 'Clientes cadastrados',
                'count' => $count,
                'cta'   => 'Ver todos',
                'link'  => '#Cliente/list',
            ];
        } catch (Throwable) {
        }

        return $badges;
    }

    private function badgesForFinanceiro(): array
    {
        $badges = [];

        // Faturas pendentes (emitida, parcialmente_paga, vencida).
        try {
            $count = $this->entityManager
                ->getRDBRepository('Fatura')
                ->where([
                    'status' => ['emitida', 'parcialmente_paga', 'vencida'],
                    'deleted' => false,
                ])
                ->count();
            $badges[] = [
                'key'   => 'fatura-pendente',
                'title' => 'Faturas pendentes',
                'count' => $count,
                'cta'   => 'Aprove',
                'link'  => '#Fatura/list',
            ];
        } catch (Throwable) {
        }

        // Lançamentos do mês corrente (P15: usar dataMovimento em BRT).
        try {
            $nowBrt     = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
            $startMonth = $nowBrt->format('Y-m-01');
            $endMonth   = $nowBrt->format('Y-m-t');
            $count      = $this->entityManager
                ->getRDBRepository('LancamentoFinanceiro')
                ->where([
                    'dataMovimento>=' => $startMonth,
                    'dataMovimento<=' => $endMonth,
                    'deleted'         => false,
                ])
                ->count();
            $badges[] = [
                'key'   => 'lancamento-mes',
                'title' => 'Lançamentos do mês',
                'count' => $count,
                'cta'   => 'Ver resumo',
                'link'  => '#LancamentoFinanceiro/list',
            ];
        } catch (Throwable) {
        }

        return $badges;
    }

    private function badgesForMarketing(): array
    {
        $badges = [];

        // Leads ativos.
        try {
            $count = $this->entityManager
                ->getRDBRepository('Lead')
                ->where([
                    'status' => ['Novo Lead', 'Qualificado'],
                    'deleted' => false,
                ])
                ->count();
            $badges[] = [
                'key'   => 'lead-ativo',
                'title' => 'Leads ativos',
                'count' => $count,
                'cta'   => 'Acompanhe',
                'link'  => '#Lead/list',
            ];
        } catch (Throwable) {
        }

        // Oportunidades em andamento (P7: excluir estágios fechados).
        try {
            $count = $this->entityManager
                ->getRDBRepository('Opportunity')
                ->where(['stage!=' => ['Closed Won', 'Closed Lost'], 'deleted' => false])
                ->count();
            $badges[] = [
                'key'   => 'oportunidade-andamento',
                'title' => 'Oportunidades em andamento',
                'count' => $count,
                'cta'   => 'Ver pipeline',
                'link'  => '#Opportunity/list',
            ];
        } catch (Throwable) {
        }

        return $badges;
    }

    private function badgesForRhLite(): array
    {
        $badges = [];

        // Funcionários ativos (P6: filtro status=ativo + título correto).
        try {
            $count = $this->entityManager
                ->getRDBRepository('Funcionario')
                ->where(['status' => 'ativo', 'deleted' => false])
                ->count();
            $badges[] = [
                'key'   => 'funcionario-ativo',
                'title' => 'Funcionários ativos',
                'count' => $count,
                'cta'   => 'Ver equipe',
                'link'  => '#Funcionario/list',
            ];
        } catch (Throwable) {
        }

        return $badges;
    }

    // ── Helpers de data ────────────────────────────────────────────────────

    private function countAudienciasSemana(): int
    {
        // P13: calcular bordas da semana no fuso BRT e converter para UTC
        // (dados armazenados em UTC no EspoCRM).
        $brt    = new DateTimeZone('America/Sao_Paulo');
        $utc    = new DateTimeZone('UTC');
        $nowBrt = new DateTimeImmutable('now', $brt);
        $dow    = (int) $nowBrt->format('N'); // 1=Mon … 7=Sun
        $mon    = $nowBrt->modify('-' . ($dow - 1) . ' days')
            ->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $sun    = $nowBrt->modify('+' . (7 - $dow) . ' days')
            ->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d H:i:s');
        return $this->entityManager
            ->getRDBRepository('Audiencia')
            ->where([
                'dataHora>=' => $mon,
                'dataHora<=' => $sun,
                'deleted'    => false,
            ])
            ->count();
    }

    private function countAudienciasHoje(): int
    {
        // P13: usar fuso BRT para "hoje" e converter bordas para UTC.
        $brt    = new DateTimeZone('America/Sao_Paulo');
        $utc    = new DateTimeZone('UTC');
        $nowBrt = new DateTimeImmutable('now', $brt);
        $start  = $nowBrt->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $end    = $nowBrt->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d H:i:s');
        return $this->entityManager
            ->getRDBRepository('Audiencia')
            ->where([
                'dataHora>=' => $start,
                'dataHora<=' => $end,
                'deleted'    => false,
            ])
            ->count();
    }
}
