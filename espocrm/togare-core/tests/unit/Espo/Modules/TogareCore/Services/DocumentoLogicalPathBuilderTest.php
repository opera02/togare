<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\DocumentoLogicalPathBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Story 5.2 — cobre sanitização de filename + build do logical path +
 * parse reverso da URI nextcloud:// (Decisões #4 + #7).
 */
final class DocumentoLogicalPathBuilderTest extends TestCase
{
    public function testSanitizeRemoveCharsNaoAsciiESpacos(): void
    {
        self::assertSame('Peticao_inicial.pdf', DocumentoLogicalPathBuilder::sanitizeFilename('Petição inicial.pdf'));
    }

    public function testSanitizeColapsaUnderscoresEParenteses(): void
    {
        self::assertSame('a_b_c.pdf', DocumentoLogicalPathBuilder::sanitizeFilename('a (b) c.pdf'));
    }

    public function testSanitizePreserveExtensionCase(): void
    {
        $result = DocumentoLogicalPathBuilder::sanitizeFilename('Foo.PDF');
        // Extensão preserva case original (.PDF não é convertida).
        self::assertSame('Foo.PDF', $result);
    }

    public function testSanitizeTrunca100Chars(): void
    {
        $base = str_repeat('a', 200);
        $original = $base . '.pdf';
        $result = DocumentoLogicalPathBuilder::sanitizeFilename($original);

        self::assertLessThanOrEqual(Documento::MAX_FILENAME_LENGTH, strlen($result));
        self::assertStringEndsWith('.pdf', $result);
    }

    public function testSanitizeFallbackArquivoQuandoBaseZerou(): void
    {
        // basename = '@#$' → tudo vira '_' → trim → '' → fallback "arquivo".
        $result = DocumentoLogicalPathBuilder::sanitizeFilename('@#$.pdf');
        self::assertSame('arquivo.pdf', $result);
    }

    public function testSanitizeStringVaziaDevolveFallback(): void
    {
        self::assertSame(Documento::FILENAME_FALLBACK, DocumentoLogicalPathBuilder::sanitizeFilename(''));
        self::assertSame(Documento::FILENAME_FALLBACK, DocumentoLogicalPathBuilder::sanitizeFilename('   '));
    }

    public function testBuildBucketProcessosComTodosCampos(): void
    {
        $doc = new Documento();
        $doc->setId('doc123abcDEF45678');
        $doc->set([
            'processoId' => 'proc001id789',
        ]);

        $logical = DocumentoLogicalPathBuilder::build($doc, 'Petição.pdf');

        self::assertSame('processos/proc001id789/doc123abcDEF45678-Peticao.pdf', $logical);
    }

    public function testBuildBucketClientes(): void
    {
        $doc = new Documento();
        $doc->setId('doc456');
        $doc->set([
            'clienteId' => 'cli001',
        ]);

        $logical = DocumentoLogicalPathBuilder::build($doc, 'contrato.docx');

        self::assertSame('clientes/cli001/doc456-contrato.docx', $logical);
    }

    public function testBuildSemAmbosLancaLogicException(): void
    {
        $doc = new Documento();
        $doc->setId('docx');

        $this->expectException(\LogicException::class);
        DocumentoLogicalPathBuilder::build($doc, 'foo.pdf');
    }

    public function testBuildBucketPrazos(): void
    {
        $doc = new Documento();
        $doc->setId('doc789xyz');
        $doc->set([
            'prazoId' => 'prz001abc',
        ]);

        $logical = DocumentoLogicalPathBuilder::build($doc, 'peticao_cumprimento.pdf');

        self::assertSame('prazos/prz001abc/doc789xyz-peticao_cumprimento.pdf', $logical);
    }

    public function testBuildComMultiplosIdsLancaLogicException(): void
    {
        $doc = new Documento();
        $doc->setId('doc123');
        $doc->set([
            'processoId' => 'proc001',
            'prazoId' => 'prz001',
        ]);

        $this->expectException(\LogicException::class);
        DocumentoLogicalPathBuilder::build($doc, 'foo.pdf');
    }

    public function testExtractLogicalPathRemoveSchemaNextcloud(): void
    {
        $logical = DocumentoLogicalPathBuilder::extractLogicalPath('nextcloud://processos/abc/def-foo.pdf');
        self::assertSame('processos/abc/def-foo.pdf', $logical);
    }

    public function testExtractLogicalPathLancaSeUriInvalida(): void
    {
        $this->expectException(\RuntimeException::class);
        DocumentoLogicalPathBuilder::extractLogicalPath('http://example.com/foo.pdf');
    }

    public function testParseFromUriRetornaComponentes(): void
    {
        // documentoId é Espo Entity ID 17-char alfanumérico (sem hífen),
        // então o primeiro `-` no tail é o separador documentoId-filename.
        $parts = DocumentoLogicalPathBuilder::parseFromUri('nextcloud://processos/proc001/doc001abc-Peticao.pdf');
        self::assertSame('processos', $parts['bucket']);
        self::assertSame('proc001', $parts['entityId']);
        self::assertSame('doc001abc', $parts['documentoId']);
        self::assertSame('Peticao.pdf', $parts['filename']);
    }

    public function testParseFromUriBucketPrazos(): void
    {
        $parts = DocumentoLogicalPathBuilder::parseFromUri('nextcloud://prazos/prz001/doc999-peticao.pdf');
        self::assertSame('prazos', $parts['bucket']);
        self::assertSame('prz001', $parts['entityId']);
        self::assertSame('doc999', $parts['documentoId']);
        self::assertSame('peticao.pdf', $parts['filename']);
    }

    public function testParseFromUriRejeitaBucketDesconhecido(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Bucket inválido/');
        DocumentoLogicalPathBuilder::parseFromUri('nextcloud://audiencias/aud001/doc999-foo.pdf');
    }
}
