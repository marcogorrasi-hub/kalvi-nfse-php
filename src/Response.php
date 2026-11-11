<?php

declare(strict_types=1);

namespace Kalvi;

/**
 * O que voltou de uma requisição, já com o corpo lido.
 */
final class Response
{
    /**
     * @param  array<string, string>  $headers  Nomes em minúsculas.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * O corpo como vetor.
     *
     * A API sempre responde JSON, mas um proxy no meio do caminho pode devolver
     * HTML de erro. Nesse caso não estoura: devolve a mensagem recortada, para
     * o erro que chega a quem chamou continuar legível.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        try {
            $parsed = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['message' => mb_substr($this->body, 0, 500)];
        }

        return is_array($parsed) ? $parsed : ['message' => mb_substr($this->body, 0, 500)];
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
