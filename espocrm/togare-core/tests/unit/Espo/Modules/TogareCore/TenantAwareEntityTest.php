<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use PHPUnit\Framework\TestCase;

class TenantAwareEntityTest extends TestCase
{
    /**
     * Entidades de infraestrutura: não armazenam dados operacionais do escritório
     * e não precisam de tenant_id. Ver fronteira em docs/entidades.md:27.
     *
     * Ao adicionar nova entrada, inclua comentário explicando por que é infraestrutura.
     * testInfrastructureEntitiesAreDocumented() garante que esta lista não apodreça.
     */
    private const INFRASTRUCTURE_ENTITIES = [
        'TogareMfaBackupCode',  // auth infra — backup code MFA vinculado a user, não a tenant (Story 2.3)
    ];

    public function testAllBusinessEntitiesUseTenantAwareTrait(): void
    {
        $root = TOGARE_PROJECT_ROOT;
        $pattern = $root . '/espocrm/*/src/files/custom/Espo/Modules/*/Entities/*.php';
        $files = \glob($pattern) ?: [];

        self::assertNotEmpty(
            $files,
            "Nenhum arquivo de entidade encontrado com o padrão:\n  $pattern\n" .
            "Verifique se TOGARE_PROJECT_ROOT aponta para a raiz correta do monorepo."
        );

        $violations = [];
        foreach ($files as $file) {
            $shortName = \pathinfo($file, PATHINFO_FILENAME);
            if (\in_array($shortName, self::INFRASTRUCTURE_ENTITIES, true)) {
                continue;
            }
            $content = \file_get_contents($file);
            if ($content === false || !\preg_match('/\buse\s+[\w\\\\]+TenantAwareEntity\s*;/m', $content)) {
                $violations[] = $shortName . "\n  → " . \str_replace('\\', '/', $file);
            }
        }

        self::assertEmpty(
            $violations,
            "Entidades de negócio sem TenantAwareEntity trait detectadas:\n\n" .
            \implode("\n", $violations) .
            "\n\nAção requerida (escolha uma):\n" .
            "  A) Adicione `use Espo\\Modules\\TogareCore\\Traits\\TenantAwareEntity;` na classe\n" .
            "     E declare tenant_id no entityDef JSON: { \"type\":\"varchar\",\"len\":40,\"notNull\":false }\n" .
            "  B) Se for entidade de infraestrutura, adicione à constante INFRASTRUCTURE_ENTITIES\n" .
            "     neste arquivo com comentário justificando por que é infraestrutura.\n"
        );
    }

    public function testInfrastructureEntitiesAreDocumented(): void
    {
        $root = TOGARE_PROJECT_ROOT;
        foreach (self::INFRASTRUCTURE_ENTITIES as $entityName) {
            $pattern = $root . '/espocrm/*/src/files/custom/Espo/Modules/*/Entities/' . $entityName . '.php';
            $matches = \glob($pattern) ?: [];
            self::assertNotEmpty(
                $matches,
                "INFRASTRUCTURE_ENTITIES contém '$entityName' mas nenhum arquivo " .
                "Entities/$entityName.php foi encontrado no monorepo.\n" .
                "Remova da lista se a entidade foi deletada ou renomeada."
            );
        }
    }
}
