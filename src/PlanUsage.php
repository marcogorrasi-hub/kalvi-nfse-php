<?php

declare(strict_types=1);

namespace Kalvi;

/**
 * Quanto do plano do mês já foi usado.
 *
 * A API só manda o cabeçalho `X-Plan-Usage` a partir de 80% do limite — antes
 * disso não vem nada, e `Kalvi::lastPlanUsage()` devolve null. Serve para você
 * avisar no seu painel antes de a nota do seu cliente começar a ser recusada
 * por limite.
 */
final class PlanUsage
{
    public function __construct(
        public readonly int $used,
        public readonly int $limit,
        /** Dia em que o contador zera, como a API mandou (AAAA-MM-DD), ou string vazia. */
        public readonly string $resets,
    ) {
    }

    /** Fração usada, de 0 a 1. */
    public function ratio(): float
    {
        return $this->limit > 0 ? $this->used / $this->limit : 0.0;
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    /**
     * Lê o cabeçalho `X-Plan-Usage`, no formato `used=4100;limit=5000;resets=2026-11-01`.
     *
     * Devolve null para cabeçalho ausente, malformado ou com limite zero — nunca
     * estoura, porque um cabeçalho estranho não pode derrubar a chamada que
     * deu certo.
     */
    public static function fromHeader(?string $header): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $campos = [];
        foreach (explode(';', $header) as $parte) {
            $pedacos = explode('=', $parte, 2);
            if (count($pedacos) === 2) {
                $campos[trim($pedacos[0])] = trim($pedacos[1]);
            }
        }

        $used = $campos['used'] ?? null;
        $limit = $campos['limit'] ?? null;
        if (!is_numeric($used) || !is_numeric($limit) || (int) $limit <= 0) {
            return null;
        }

        return new self((int) $used, (int) $limit, $campos['resets'] ?? '');
    }
}
