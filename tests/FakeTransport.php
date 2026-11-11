<?php

declare(strict_types=1);

namespace Kalvi\Tests;

use Kalvi\KalviNetworkError;
use Kalvi\Response;
use Kalvi\Transport;

/**
 * Dublê de rede. Devolve respostas preparadas, na ordem, e guarda o que recebeu.
 *
 * Uma entrada da fila pode ser uma Response ou uma KalviNetworkError — assim o
 * mesmo dublê testa retry por status e retry por queda de rede.
 */
final class FakeTransport implements Transport
{
    /** @var list<Response|KalviNetworkError> */
    private array $fila;

    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>, timeout: float}> */
    public array $chamadas = [];

    /**
     * @param  list<Response|KalviNetworkError>  $fila
     */
    public function __construct(array $fila)
    {
        $this->fila = $fila;
    }

    public static function json(int $status, array $corpo, array $headers = []): Response
    {
        return new Response($status, json_encode($corpo, JSON_THROW_ON_ERROR), $headers);
    }

    public function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        float $timeoutSeconds,
    ): Response {
        $this->chamadas[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
        ];

        $proxima = array_shift($this->fila);
        if ($proxima === null) {
            throw new \LogicException('O dublê ficou sem resposta preparada: o SDK chamou mais vezes que o esperado.');
        }
        if ($proxima instanceof KalviNetworkError) {
            throw $proxima;
        }

        return $proxima;
    }

    public function contagem(): int
    {
        return count($this->chamadas);
    }

    /**
     * @return array{method: string, url: string, body: ?string, headers: array<string, string>, timeout: float}
     */
    public function ultima(): array
    {
        if ($this->chamadas === []) {
            throw new \LogicException('Nenhuma chamada foi feita.');
        }

        return $this->chamadas[count($this->chamadas) - 1];
    }
}
