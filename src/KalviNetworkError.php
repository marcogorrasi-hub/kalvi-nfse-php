<?php

declare(strict_types=1);

namespace Kalvi;

/**
 * Falha de rede ou tempo esgotado: a chamada não chegou a ser respondida.
 *
 * É diferente de {@see KalviError}, que é a API respondendo que algo está
 * errado. Aqui ninguém respondeu — pode ser DNS, firewall da empresa, ou a
 * nossa API fora do ar. Repetir mais tarde faz sentido; corrigir a nota, não.
 */
final class KalviNetworkError extends \RuntimeException
{
    public readonly int $attempts;

    public function __construct(string $message, int $attempts, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->attempts = $attempts;
    }
}
