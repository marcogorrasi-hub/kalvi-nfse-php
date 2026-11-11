<?php

declare(strict_types=1);

namespace Kalvi;

/**
 * Transporte padrão, em cURL.
 *
 * cURL em vez de Guzzle de propósito: o pacote fica **sem dependência nenhuma**
 * além das extensões que toda instalação de PHP já tem. Numa software house com
 * sistema antigo, "composer require" que arrasta sete pacotes é motivo real para
 * desistir da integração.
 */
final class CurlTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        float $timeoutSeconds,
    ): Response {
        $handle = curl_init();
        if ($handle === false) {
            throw new KalviNetworkError('Não foi possível iniciar o cURL.', 1);
        }

        $linhas = [];
        foreach ($headers as $nome => $valor) {
            $linhas[] = $nome . ': ' . $valor;
        }

        $recebidos = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $linhas,
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeoutSeconds, 10.0) * 1000),
            // Sem seguir redirecionamento: a API não redireciona, e seguir às
            // cegas mandaria a chave para outro host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($_, string $linha) use (&$recebidos): int {
                $tamanho = strlen($linha);
                $partes = explode(':', $linha, 2);
                if (count($partes) === 2) {
                    $recebidos[strtolower(trim($partes[0]))] = trim($partes[1]);
                }

                return $tamanho;
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $retorno = curl_exec($handle);
        $erro = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($retorno === false || $status === 0) {
            throw new KalviNetworkError(
                $erro !== '' ? 'A API não respondeu: ' . $erro : 'A API não respondeu.',
                1,
            );
        }

        return new Response($status, is_string($retorno) ? $retorno : '', $recebidos);
    }
}
