<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareLicensing\Service\LicenseRevalidator;
use Espo\ORM\EntityManager;

/**
 * Adapter EspoCRM Scheduled Job — delega lógica para LicenseRevalidator
 * (que é puro PDO + EventBus, testável sem EspoCRM).
 *
 * Cron padrão: 0 4 * * * (04:00 BRT).
 */
final class RevalidateLicensesJob implements Job
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly EventBusContract $eventBus,
    ) {
    }

    public function run(Data $data): void
    {
        $revalidator = new LicenseRevalidator($this->entityManager->getPDO(), $this->eventBus);
        $revalidator->revalidate();
    }
}
