<?php

declare(strict_types=1);

namespace Kalvi;

/**
 * O canal por onde a requisição sai.
 *
 * Existe por um motivo só: deixar o teste trocar a rede por um dublê. O SDK em
 * TypeScript faz isso aceitando um `fetch` alternativo; aqui a peça trocável é
 * esta interface. Sem ela, testar retry e tempo esgotado exigiria servidor de
 * verdade.
 */
interface Transport
{
    /**
     * Dispara uma requisição e devolve o que voltou.
     *
     * Não deve lançar por status de erro: 4xx e 5xx são resposta válida e quem
     * decide o que fazer com elas é o {@see Kalvi}. Deve lançar
     * {@see KalviNetworkError} quando não houve resposta nenhuma — DNS, conexão
     * recusada, tempo esgotado.
     *
     * @param  array<string, string>  $headers
     * @throws KalviNetworkError
     */
    public function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        float $timeoutSeconds,
    ): Response;
}
