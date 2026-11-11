<?php

declare(strict_types=1);

namespace Kalvi;

/**
 * Erro devolvido pela API, já aberto em propriedades.
 *
 * Sempre traz `code` e `requestId` — cite o requestId no suporte. Quando o
 * limite do mês acaba, traz `upgradeUrl` e `resetsAt`.
 *
 * Espelha o `KalviError` do SDK em TypeScript: mesmos campos, mesmos nomes de
 * código. Se os dois divergirem, o do TypeScript é a referência.
 */
class KalviError extends \RuntimeException
{
    /**
     * Código estável da API: UNAUTHORIZED, INVALID_INPUT, QUOTA_EXCEEDED, RATE_LIMITED, PARSE_ERROR...
     *
     * Chama-se `errorCode`, e não `code`, por imposição do PHP: a classe
     * `Exception` já tem uma propriedade `$code` — que é **número** e não é
     * readonly —, e redeclará-la é erro fatal ("Cannot redeclare non-readonly
     * property Exception::$code as readonly"). O CI pegou isso nas quatro
     * versões antes de o pacote ir para o Packagist.
     *
     * No SDK em TypeScript o campo é `err.code`; aqui é `$e->errorCode`. A
     * diferença é do PHP, não nossa.
     */
    public readonly string $errorCode;

    public readonly int $status;

    public readonly ?string $requestId;

    /** @var mixed */
    public readonly mixed $details;

    public readonly ?string $docsUrl;

    public readonly ?string $apiVersion;

    /** Versão da tabela usada na resposta. Guarde junto com o resultado. */
    public readonly ?string $tablesVersion;

    /** Só em QUOTA_EXCEEDED: para onde mandar quem quer subir de plano. */
    public readonly ?string $upgradeUrl;

    /** Só em QUOTA_EXCEEDED: dia em que o contador zera (AAAA-MM-DD). */
    public readonly ?string $resetsAt;

    /** Segundos sugeridos pelo cabeçalho Retry-After, quando houver. */
    public readonly ?int $retryAfterSeconds;

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(int $status, array $body, ?int $retryAfterSeconds = null)
    {
        $code = self::texto($body, 'code') ?? 'HTTP_' . $status;
        parent::__construct(
            self::texto($body, 'message') ?? sprintf('A API respondeu %d (%s).', $status, $code),
        );

        $details = $body['details'] ?? null;

        $this->errorCode = $code;
        $this->status = $status;
        $this->requestId = self::texto($body, 'request_id');
        $this->details = $details;
        $this->docsUrl = self::texto($body, 'docs_url');
        $this->apiVersion = self::texto($body, 'api_version');
        $this->tablesVersion = self::texto($body, 'tables_version');
        $this->upgradeUrl = is_array($details) ? self::texto($details, 'upgrade_url') : null;
        $this->resetsAt = is_array($details) ? self::texto($details, 'resets_at') : null;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    /** true quando o limite mensal do plano acabou: não adianta repetir, assine ou espere zerar. */
    public function isQuotaExceeded(): bool
    {
        return $this->errorCode === 'QUOTA_EXCEEDED';
    }

    /** true quando foi rajada. O SDK já tentou de novo dentro do limite de tentativas. */
    public function isRateLimited(): bool
    {
        return $this->errorCode === 'RATE_LIMITED';
    }

    /** true quando a chave está errada, revogada ou ausente. */
    public function isUnauthorized(): bool
    {
        return $this->errorCode === 'UNAUTHORIZED';
    }

    /**
     * @param  array<string, mixed>  $fonte
     */
    private static function texto(array $fonte, string $chave): ?string
    {
        $valor = $fonte[$chave] ?? null;

        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
