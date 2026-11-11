<?php

declare(strict_types=1);

namespace Kalvi\Tests;

use Kalvi\Kalvi;
use Kalvi\KalviError;
use Kalvi\KalviNetworkError;
use Kalvi\PlanUsage;
use Kalvi\Response;
use PHPUnit\Framework\TestCase;

final class KalviTest extends TestCase
{
    /**
     * Monta o cliente com rede e relógio falsos.
     *
     * As esperas vão para um `ArrayObject` e não para um vetor com referência.
     * A primeira versão devolvia `[$kalvi, $transporte, &$esperas]`: ao
     * desempacotar com `[$a, $b, $c] = ...`, o PHP **copia o valor** do momento
     * do retorno (vazio), e a asserção sobre o tempo de espera passaria a olhar
     * um vetor que nunca enche. Objeto é compartilhado por identidade e não tem
     * esse problema.
     *
     * @param  list<Response|KalviNetworkError>  $fila
     * @param  array<string, mixed>  $extras
     * @return array{0: Kalvi, 1: FakeTransport, 2: \ArrayObject<int, int>}
     */
    private function montar(array $fila, array $extras = []): array
    {
        $transporte = new FakeTransport($fila);
        /** @var \ArrayObject<int, int> $esperas */
        $esperas = new \ArrayObject();
        $kalvi = new Kalvi(array_merge([
            'apiKey' => 'chave-de-teste',
            'transport' => $transporte,
            'sleeper' => function (int $ms) use ($esperas): void {
                $esperas[] = $ms;
            },
        ], $extras));

        return [$kalvi, $transporte, $esperas];
    }

    // ------------------------------------------------------------ o caminho feliz

    public function testValidateMandaXmlCruComOTipoCerto(): void
    {
        [$kalvi, $transporte] = $this->montar([
            FakeTransport::json(200, ['issues' => [], 'tables_version' => '2026-10-11']),
        ]);

        $resultado = $kalvi->validate('<DPS><infDPS/></DPS>');

        $this->assertSame('2026-10-11', $resultado['tables_version']);
        $chamada = $transporte->ultima();
        $this->assertSame('POST', $chamada['method']);
        $this->assertSame('https://api.kalvi.com.br/v1/validate', $chamada['url']);
        $this->assertSame('application/xml', $chamada['headers']['Content-Type']);
        $this->assertSame('<DPS><infDPS/></DPS>', $chamada['body']);
        $this->assertSame('Bearer chave-de-teste', $chamada['headers']['Authorization']);
    }

    public function testValidateComObjetoVaiComoJson(): void
    {
        [$kalvi, $transporte] = $this->montar([FakeTransport::json(200, ['issues' => []])]);

        $kalvi->validate(['DPS' => ['infDPS' => ['dCompet' => '2026-10-01']]]);

        $chamada = $transporte->ultima();
        $this->assertSame('application/json', $chamada['headers']['Content-Type']);
        $this->assertSame('{"DPS":{"infDPS":{"dCompet":"2026-10-01"}}}', $chamada['body']);
    }

    public function testClassifyMandaOCorpoEmJson(): void
    {
        [$kalvi, $transporte] = $this->montar([FakeTransport::json(200, ['candidates' => []])]);

        $kalvi->classify(['lc116Item' => '01.05', 'regime' => 'normal']);

        $chamada = $transporte->ultima();
        $this->assertSame('https://api.kalvi.com.br/v1/classify', $chamada['url']);
        $this->assertStringContainsString('"lc116Item":"01.05"', (string) $chamada['body']);
    }

    public function testAcentoNaoViraSequenciaDeEscape(): void
    {
        // JSON_UNESCAPED_UNICODE: sem isso, "Serviço" vira "Serviço" no
        // corpo. Funciona, mas é ilegível no log de quem integra — e esse log é
        // a primeira coisa que o dev abre quando a nota é recusada.
        [$kalvi, $transporte] = $this->montar([FakeTransport::json(200, [])]);

        $kalvi->classify(['descricao' => 'Serviço de manutenção']);

        $this->assertStringContainsString('Serviço de manutenção', (string) $transporte->ultima()['body']);
    }

    public function testRotasDeLeituraNaoMandamCorpoNemContentType(): void
    {
        [$kalvi, $transporte] = $this->montar([
            FakeTransport::json(200, ['versions' => ['2026-10-11']]),
            FakeTransport::json(200, ['changes' => []]),
            FakeTransport::json(200, ['used' => 10]),
        ]);

        $kalvi->tables();
        $this->assertNull($transporte->ultima()['body']);
        $this->assertArrayNotHasKey('Content-Type', $transporte->ultima()['headers']);

        $kalvi->changelog('2026-10-11');
        $this->assertSame('https://api.kalvi.com.br/v1/tables/2026-10-11/changelog', $transporte->ultima()['url']);

        $kalvi->usage();
        $this->assertSame('https://api.kalvi.com.br/v1/account/usage', $transporte->ultima()['url']);
    }

    public function testVersaoNaUrlEEscapada(): void
    {
        [$kalvi, $transporte] = $this->montar([FakeTransport::json(200, [])]);

        $kalvi->changelog('../admin');

        $this->assertStringNotContainsString('/admin', $transporte->ultima()['url']);
        $this->assertStringContainsString('%2F', $transporte->ultima()['url']);
    }

    // ------------------------------------------------------------ o lote

    public function testLoteVaiComAChaveNotes(): void
    {
        [$kalvi, $transporte] = $this->montar([FakeTransport::json(200, ['results' => []])]);

        $kalvi->validateMany(['<DPS/>', ['DPS' => []]]);

        $corpo = json_decode((string) $transporte->ultima()['body'], true);
        $this->assertCount(2, $corpo['notes']);
        $this->assertSame('<DPS/>', $corpo['notes'][0]);
    }

    public function testLoteVazioEstouraAntesDeGastarChamada(): void
    {
        [$kalvi, $transporte] = $this->montar([]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $kalvi->validateMany([]);
        } finally {
            $this->assertSame(0, $transporte->contagem(), 'não pode ter saído da máquina');
        }
    }

    public function testLoteGrandeDemaisEstouraAntesDeGastarChamada(): void
    {
        [$kalvi, $transporte] = $this->montar([]);

        $this->expectExceptionMessageMatches('/no máximo 50 notas \(recebeu 51\)/');
        try {
            $kalvi->validateMany(array_fill(0, 51, '<DPS/>'));
        } finally {
            $this->assertSame(0, $transporte->contagem());
        }
    }

    public function testXmlVazioEstouraAntesDeGastarChamada(): void
    {
        [$kalvi, $transporte] = $this->montar([]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $kalvi->validate('   ');
        } finally {
            $this->assertSame(0, $transporte->contagem());
        }
    }

    // ------------------------------------------------------------ erro da API

    public function testErroDaApiVemAbertoEmPropriedades(): void
    {
        [$kalvi] = $this->montar([
            FakeTransport::json(401, [
                'code' => 'UNAUTHORIZED',
                'message' => 'Chave de API inválida ou revogada.',
                'docs_url' => 'https://kalvi.com.br/docs/api/erros/UNAUTHORIZED',
                'request_id' => 'req_abc',
                'api_version' => '1',
                'tables_version' => '2026-10-11',
            ]),
        ]);

        try {
            $kalvi->validate('<DPS/>');
            $this->fail('deveria ter estourado');
        } catch (KalviError $erro) {
            $this->assertSame('UNAUTHORIZED', $erro->errorCode);
            $this->assertSame(401, $erro->status);
            $this->assertSame('req_abc', $erro->requestId);
            $this->assertSame('2026-10-11', $erro->tablesVersion);
            $this->assertTrue($erro->isUnauthorized());
            $this->assertFalse($erro->isQuotaExceeded());
            $this->assertSame('Chave de API inválida ou revogada.', $erro->getMessage());
        }
    }

    public function testQuotaExcedidaTrazParaOndeMandarOCliente(): void
    {
        [$kalvi] = $this->montar([
            FakeTransport::json(429, [
                'code' => 'QUOTA_EXCEEDED',
                'message' => 'Limite do mês atingido.',
                'details' => ['upgrade_url' => 'https://kalvi.com.br/planos', 'resets_at' => '2026-11-01'],
            ]),
        ]);

        try {
            $kalvi->validate('<DPS/>');
            $this->fail('deveria ter estourado');
        } catch (KalviError $erro) {
            $this->assertTrue($erro->isQuotaExceeded());
            $this->assertSame('https://kalvi.com.br/planos', $erro->upgradeUrl);
            $this->assertSame('2026-11-01', $erro->resetsAt);
        }
    }

    public function testQuotaExcedidaNaoERepetida(): void
    {
        // QUOTA_EXCEEDED e RATE_LIMITED chegam os dois como 429. Repetir o
        // primeiro é inútil (o mês acabou) e só queima tempo de quem chamou.
        [$kalvi, $transporte] = $this->montar([
            FakeTransport::json(429, ['code' => 'QUOTA_EXCEEDED']),
        ]);

        $this->expectException(KalviError::class);
        try {
            $kalvi->validate('<DPS/>');
        } finally {
            $this->assertSame(1, $transporte->contagem(), 'não pode ter tentado de novo');
        }
    }

    public function testRespostaQueNaoEJsonNaoDerrubaOErro(): void
    {
        // `maxRetries => 0` porque 502 é erro de servidor e o SDK repete nele
        // por projeto. Sem isso o teste enfileirava uma resposta e o cliente
        // pedia três, estourando no dublê em vez de provar o que interessa
        // aqui: que um corpo que não é JSON (HTML de proxy, por exemplo) ainda
        // produz um erro legível, e não um estouro de parse.
        [$kalvi] = $this->montar([new Response(502, '<html>Bad Gateway</html>', [])], ['maxRetries' => 0]);

        try {
            $kalvi->validate('<DPS/>');
            $this->fail('deveria ter estourado');
        } catch (KalviError $erro) {
            $this->assertSame('HTTP_502', $erro->errorCode);
            $this->assertStringContainsString('Bad Gateway', $erro->getMessage());
        }
    }

    // ------------------------------------------------------------ repetição

    public function testRajadaERepetidaERespeitaORetryAfter(): void
    {
        [$kalvi, $transporte, $esperas] = $this->montar([
            FakeTransport::json(429, ['code' => 'RATE_LIMITED'], ['retry-after' => '2']),
            FakeTransport::json(200, ['issues' => []]),
        ]);

        $kalvi->validate('<DPS/>');

        $this->assertSame(2, $transporte->contagem());
        $this->assertSame([2000], $esperas->getArrayCopy(), 'tem que esperar os 2 s que a API pediu');
    }

    public function testErroDeServidorERepetido(): void
    {
        [$kalvi, $transporte] = $this->montar([
            FakeTransport::json(500, ['code' => 'INTERNAL']),
            FakeTransport::json(200, ['issues' => []]),
        ]);

        $kalvi->validate('<DPS/>');

        $this->assertSame(2, $transporte->contagem());
    }

    public function testEntradaInvalidaNaoERepetida(): void
    {
        [$kalvi, $transporte] = $this->montar([
            FakeTransport::json(400, ['code' => 'INVALID_INPUT']),
        ]);

        $this->expectException(KalviError::class);
        try {
            $kalvi->validate('<DPS/>');
        } finally {
            $this->assertSame(1, $transporte->contagem(), 'repetir não conserta a entrada');
        }
    }

    public function testQuedaDeRedeERepetidaEDepoisEstoura(): void
    {
        [$kalvi, $transporte] = $this->montar([
            new KalviNetworkError('sem rota', 1),
            new KalviNetworkError('sem rota', 1),
            new KalviNetworkError('sem rota', 1),
        ]);

        try {
            $kalvi->validate('<DPS/>');
            $this->fail('deveria ter estourado');
        } catch (KalviNetworkError $erro) {
            $this->assertSame(3, $erro->attempts);
            $this->assertSame(3, $transporte->contagem(), 'uma original + 2 tentativas');
            $this->assertStringContainsString('3 tentativas', $erro->getMessage());
        }
    }

    public function testMaxRetriesZeroNaoRepete(): void
    {
        [$kalvi, $transporte] = $this->montar(
            [FakeTransport::json(500, ['code' => 'INTERNAL'])],
            ['maxRetries' => 0],
        );

        $this->expectException(KalviError::class);
        try {
            $kalvi->validate('<DPS/>');
        } finally {
            $this->assertSame(1, $transporte->contagem());
        }
    }

    // ------------------------------------------------------------ uso do plano

    public function testCabecalhoDeUsoEhLidoEAvisado(): void
    {
        $avisos = [];
        [$kalvi] = $this->montar(
            [FakeTransport::json(200, [], ['x-plan-usage' => 'used=4100;limit=5000;resets=2026-11-01'])],
            ['onPlanUsage' => function (PlanUsage $uso) use (&$avisos): void {
                $avisos[] = $uso;
            }],
        );

        $kalvi->validate('<DPS/>');

        $this->assertCount(1, $avisos);
        $this->assertSame(4100, $avisos[0]->used);
        $this->assertSame(900, $avisos[0]->remaining());
        $this->assertSame(0.82, round($avisos[0]->ratio(), 2));
        $this->assertSame('2026-11-01', $kalvi->lastPlanUsage()?->resets);
    }

    public function testSemCabecalhoDeUsoOUltimoFicaNulo(): void
    {
        [$kalvi] = $this->montar([FakeTransport::json(200, [])]);

        $kalvi->validate('<DPS/>');

        $this->assertNull($kalvi->lastPlanUsage());
    }

    /**
     * Um cabeçalho estranho não pode derrubar a chamada que deu certo.
     *
     * @dataProvider cabecalhosRuins
     */
    public function testCabecalhoDeUsoRuimEIgnorado(?string $header): void
    {
        $this->assertNull(PlanUsage::fromHeader($header));
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function cabecalhosRuins(): iterable
    {
        yield 'ausente' => [null];
        yield 'vazio' => ['   '];
        yield 'sem os campos' => ['oi'];
        yield 'limite zero' => ['used=1;limit=0'];
        yield 'nao numerico' => ['used=muito;limit=5000'];
        yield 'so o used' => ['used=10'];
    }

    // ------------------------------------------------------------ cabeçalhos e configuração

    public function testSemChaveNaoMandaCabecalhoDeAutorizacao(): void
    {
        $transporte = new FakeTransport([FakeTransport::json(200, [])]);
        $kalvi = new Kalvi(['apiKey' => '', 'transport' => $transporte]);

        $kalvi->tables();

        $this->assertArrayNotHasKey('Authorization', $transporte->ultima()['headers']);
    }

    public function testUserAgentIdentificaOSdkEAplicacaoDeQuemIntegra(): void
    {
        [$kalvi, $transporte] = $this->montar(
            [FakeTransport::json(200, [])],
            ['userAgent' => 'ERP-da-Casa/3.2'],
        );

        $kalvi->tables();

        $agente = $transporte->ultima()['headers']['User-Agent'];
        $this->assertStringStartsWith('kalvi-nfse-php/', $agente);
        $this->assertStringContainsString('ERP-da-Casa/3.2', $agente);
    }

    public function testBaseUrlPersonalizadaPerdeABarraDoFim(): void
    {
        [$kalvi, $transporte] = $this->montar(
            [FakeTransport::json(200, [])],
            ['baseUrl' => 'https://homologacao.kalvi.com.br/v1/'],
        );

        $kalvi->tables();

        $this->assertSame('https://homologacao.kalvi.com.br/v1/tables', $transporte->ultima()['url']);
    }

    public function testTimeoutChegaEmSegundosNoTransporte(): void
    {
        [$kalvi, $transporte] = $this->montar(
            [FakeTransport::json(200, [])],
            ['timeoutMs' => 4500],
        );

        $kalvi->tables();

        $this->assertSame(4.5, $transporte->ultima()['timeout']);
    }

    // ------------------------------------------------------------ Retry-After e backoff

    /**
     * @dataProvider retryAfters
     */
    public function testParseRetryAfter(?string $header, ?int $esperado): void
    {
        $this->assertSame($esperado, Kalvi::parseRetryAfter($header));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?int}>
     */
    public static function retryAfters(): iterable
    {
        yield 'ausente' => [null, null];
        yield 'vazio' => ['  ', null];
        yield 'segundos' => ['7', 7];
        yield 'zero' => ['0', 0];
        yield 'negativo e recusado' => ['-5', null];
        yield 'texto sem sentido' => ['logo', null];
    }

    public function testBackoffRespeitaOTeto(): void
    {
        $this->assertSame(8000, Kalvi::backoffMs(0, 30), 'Retry-After gigante cai no teto');
        $this->assertSame(8000, Kalvi::backoffMs(10, null), 'exponencial cai no teto');
        $this->assertGreaterThanOrEqual(250, Kalvi::backoffMs(0, null));
        $this->assertLessThanOrEqual(500, Kalvi::backoffMs(0, null));
    }

    // ------------------------------------------------------------ a regra do ADR-015

    public function testOPacoteNaoCarregaRegraTributaria(): void
    {
        // ADR-015: o motor, as tabelas e as fixtures são fechados. Este SDK só
        // fala o contrato HTTP. Se um dia alguém colar tabela aqui dentro, este
        // teste quebra — é o guarda da regra 6 do projeto no lado PHP.
        //
        // O guarda procura **dado**, não palavra: citar "cClassTrib" num
        // comentário que explica o que o endpoint devolve é correto e
        // necessário. O que não pode entrar é valor de tabela — um código de
        // cClassTrib (6 dígitos), um código oficial de rejeição (E seguido de 4
        // dígitos) ou uma alíquota escrita no código.
        $arquivos = glob(__DIR__ . '/../src/*.php') ?: [];
        $this->assertNotEmpty($arquivos, 'não achei o código-fonte');

        foreach ($arquivos as $arquivo) {
            $conteudo = (string) file_get_contents($arquivo);
            $nome = basename($arquivo);

            $this->assertSame(
                0,
                preg_match('/\b\d{6}\b/', $conteudo),
                sprintf('%s tem um número de 6 dígitos: parece código de cClassTrib', $nome),
            );
            $this->assertSame(
                0,
                preg_match('/\bE\d{4}\b/', $conteudo),
                sprintf('%s tem um código oficial de rejeição: tabela não entra no SDK', $nome),
            );
            $this->assertSame(
                0,
                preg_match('/\d+[.,]\d+\s*%/', $conteudo),
                sprintf('%s tem uma alíquota escrita no código', $nome),
            );
        }
    }
}
