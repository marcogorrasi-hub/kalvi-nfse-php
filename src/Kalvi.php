<?php

declare(strict_types=1);

namespace Kalvi;

/**
 * Cliente da API Kalvi.
 *
 * **Não contém regra tributária nenhuma.** Tudo vem da API, e toda resposta traz
 * `tables_version` dizendo qual versão da tabela foi usada — guarde esse valor
 * junto com o resultado, porque a mesma nota pode ter respostas diferentes em
 * versões diferentes (ADR-015 e a regra 1 do projeto).
 *
 * Use no servidor. A chave não deve ir para o navegador nem para o aplicativo
 * do cliente final.
 *
 * ```php
 * $kalvi = new Kalvi\Kalvi(['apiKey' => getenv('KALVI_API_KEY')]);
 * $resultado = $kalvi->validate(file_get_contents('dps.xml'));
 * foreach ($resultado['issues'] ?? [] as $erro) {
 *     echo $erro['code'], ' ', $erro['message'], PHP_EOL;
 * }
 * ```
 */
final class Kalvi
{
    public const DEFAULT_BASE_URL = 'https://api.kalvi.com.br/v1';

    /** Teto do lote numa chamada só. Recusamos antes de sair da sua máquina. */
    public const MAX_BATCH = 50;

    public const VERSION = '0.1.0';

    /** Códigos que vale repetir. Os outros são problema da entrada, e repetir não conserta. */
    private const RETRIABLE_CODES = ['RATE_LIMITED'];

    private const BASE_BACKOFF_MS = 250;

    private const MAX_BACKOFF_MS = 8000;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly float $timeoutSeconds;

    private readonly int $maxRetries;

    private readonly Transport $transport;

    private readonly string $userAgent;

    /** @var null|callable(PlanUsage): void */
    private $onPlanUsage;

    private ?PlanUsage $lastPlanUsage = null;

    /** @var null|callable(int): void Espera, em milissegundos. Trocável no teste. */
    private $sleeper;

    /**
     * @param array{
     *     apiKey?: string,
     *     baseUrl?: string,
     *     timeoutMs?: int,
     *     maxRetries?: int,
     *     transport?: Transport,
     *     onPlanUsage?: callable(PlanUsage): void,
     *     userAgent?: string,
     *     sleeper?: callable(int): void,
     * } $options
     */
    public function __construct(array $options = [])
    {
        $chave = $options['apiKey'] ?? getenv('KALVI_API_KEY');
        $base = $options['baseUrl'] ?? getenv('KALVI_BASE_URL');

        $this->apiKey = is_string($chave) ? $chave : '';
        $this->baseUrl = rtrim(is_string($base) && $base !== '' ? $base : self::DEFAULT_BASE_URL, '/');
        $this->timeoutSeconds = ($options['timeoutMs'] ?? 20000) / 1000;
        $this->maxRetries = max(0, $options['maxRetries'] ?? 2);
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->onPlanUsage = $options['onPlanUsage'] ?? null;
        $this->sleeper = $options['sleeper'] ?? null;

        $sufixo = $options['userAgent'] ?? null;
        $this->userAgent = 'kalvi-nfse-php/' . self::VERSION . (is_string($sufixo) && $sufixo !== '' ? ' ' . $sufixo : '');
    }

    /**
     * Candidatos de CST e cClassTrib para um serviço, com justificativa, alíquotas e fontes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws KalviError|KalviNetworkError
     */
    public function classify(array $input): array
    {
        return $this->request('POST', '/classify', $this->encodeJson($input), 'application/json');
    }

    /**
     * Confere uma DPS antes de transmitir.
     *
     * Aceita o XML como string (vai como `application/xml`) ou o objeto já
     * montado, no formato `['DPS' => [...]]` (vai como JSON). **Nada do conteúdo
     * da nota é guardado pela API.**
     *
     * @param  string|array<string, mixed>  $dps
     * @return array<string, mixed>
     *
     * @throws KalviError|KalviNetworkError
     */
    public function validate(string|array $dps): array
    {
        if (is_string($dps)) {
            if (trim($dps) === '') {
                throw new \InvalidArgumentException('O XML da DPS está vazio.');
            }

            return $this->request('POST', '/validate', $dps, 'application/xml');
        }

        return $this->request('POST', '/validate', $this->encodeJson($dps), 'application/json');
    }

    /**
     * Confere um lote de até 50 notas numa chamada só.
     *
     * Cada item pode ser o XML em string ou o objeto da DPS. Lote vazio ou grande
     * demais é recusado aqui, sem gastar chamada do seu plano.
     *
     * @param  array<int, string|array<string, mixed>>  $notes
     * @return array<string, mixed>
     *
     * @throws KalviError|KalviNetworkError
     */
    public function validateMany(array $notes): array
    {
        $quantidade = count($notes);
        if ($quantidade === 0) {
            throw new \InvalidArgumentException('Envie pelo menos uma nota.');
        }
        if ($quantidade > self::MAX_BATCH) {
            throw new \InvalidArgumentException(
                sprintf('O lote aceita no máximo %d notas (recebeu %d).', self::MAX_BATCH, $quantidade),
            );
        }

        return $this->request(
            'POST',
            '/validate',
            $this->encodeJson(['notes' => array_values($notes)]),
            'application/json',
        );
    }

    /**
     * As versões de tabela disponíveis.
     *
     * @return array<string, mixed>
     */
    public function tables(): array
    {
        return $this->request('GET', '/tables', null, null);
    }

    /**
     * O que mudou numa versão de tabela. Sem argumento, a mais recente.
     *
     * @return array<string, mixed>
     */
    public function changelog(string $version = 'latest'): array
    {
        return $this->request('GET', '/tables/' . rawurlencode($version) . '/changelog', null, null);
    }

    /**
     * Quanto do plano do mês já foi usado, direto da API.
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->request('GET', '/account/usage', null, null);
    }

    /**
     * Último `X-Plan-Usage` visto, ou null se ainda não passou de 80% do limite.
     */
    public function lastPlanUsage(): ?PlanUsage
    {
        return $this->lastPlanUsage;
    }

    /**
     * Segundos do cabeçalho Retry-After. Aceita número de segundos e data HTTP.
     */
    public static function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $texto = trim($header);
        if (is_numeric($texto)) {
            $segundos = (int) $texto;

            return $segundos >= 0 ? $segundos : null;
        }

        $quando = strtotime($texto);
        if ($quando === false) {
            return null;
        }

        return max(0, (int) ceil($quando - time()));
    }

    /**
     * Quanto esperar antes da próxima tentativa, em milissegundos.
     *
     * Respeita o Retry-After quando a API manda. Sem ele, dobra o tempo a cada
     * tentativa e soma um embaralhado, para dois processos do mesmo cliente não
     * repetirem no mesmo instante.
     */
    public static function backoffMs(int $attempt, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null) {
            return (int) min($retryAfterSeconds * 1000, self::MAX_BACKOFF_MS);
        }

        $exponencial = self::BASE_BACKOFF_MS * (2 ** $attempt);
        $embaralhado = random_int(0, self::BASE_BACKOFF_MS);

        return (int) min($exponencial + $embaralhado, self::MAX_BACKOFF_MS);
    }

    /**
     * @param  array<string, mixed>  $valor
     */
    private function encodeJson(array $valor): string
    {
        return json_encode($valor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $contentType): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws KalviError|KalviNetworkError
     */
    private function request(string $method, string $path, ?string $body, ?string $contentType): array
    {
        $url = $this->baseUrl . $path;
        $ultimaFalhaDeRede = null;

        for ($tentativa = 0; $tentativa <= $this->maxRetries; $tentativa++) {
            try {
                $resposta = $this->transport->send(
                    $method,
                    $url,
                    $body,
                    $this->headers($contentType),
                    $this->timeoutSeconds,
                );
            } catch (KalviNetworkError $erro) {
                $ultimaFalhaDeRede = $erro;
                if ($tentativa === $this->maxRetries) {
                    break;
                }
                $this->sleep(self::backoffMs($tentativa, null));

                continue;
            }

            $uso = PlanUsage::fromHeader($resposta->header('x-plan-usage'));
            if ($uso !== null) {
                $this->lastPlanUsage = $uso;
                if ($this->onPlanUsage !== null) {
                    ($this->onPlanUsage)($uso);
                }
            }

            if ($resposta->isSuccess()) {
                return $resposta->json();
            }

            $corpo = $resposta->json();
            $retryAfter = self::parseRetryAfter($resposta->header('retry-after'));
            $code = is_string($corpo['code'] ?? null) ? $corpo['code'] : '';
            $valeRepetir = $resposta->status >= 500 || in_array($code, self::RETRIABLE_CODES, true);

            if (!$valeRepetir || $tentativa === $this->maxRetries) {
                throw new KalviError($resposta->status, $corpo, $retryAfter);
            }

            $this->sleep(self::backoffMs($tentativa, $retryAfter));
        }

        throw new KalviNetworkError(
            sprintf('A API não respondeu depois de %d tentativas.', $this->maxRetries + 1),
            $this->maxRetries + 1,
            $ultimaFalhaDeRede,
        );
    }

    private function sleep(int $milissegundos): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($milissegundos);

            return;
        }

        usleep($milissegundos * 1000);
    }
}
