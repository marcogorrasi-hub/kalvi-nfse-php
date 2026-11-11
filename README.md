# kalvi/nfse

Confira a NFS-e do padrão nacional para IBS/CBS **antes de transmitir**, em PHP. A API aponta o que seria
rejeitado (com o código oficial, o campo e a correção) e sugere CST e cClassTrib para o item da LC 116.

```php
$kalvi = new Kalvi\Kalvi(['apiKey' => getenv('KALVI_API_KEY')]);
$r = $kalvi->validate(file_get_contents('dps.xml'));
foreach ($r['issues'] ?? [] as $erro) {
    echo $erro['severity'], ' ', $erro['code'], ' ', $erro['message'], ' → ', $erro['fix'] ?? '', PHP_EOL;
}
```

**Chave grátis:** 200 chamadas por mês, sem cartão, a API inteira já no grátis.
[Criar chave](https://kalvi.com.br/cadastro?utm_source=packagist&utm_medium=sdk-php) ·
[Documentação](https://kalvi.com.br/docs/api?utm_source=packagist&utm_medium=sdk-php)

```bash
composer require kalvi/nfse
```

PHP 8.1 ou mais novo, com `curl` e `json`. Sem outras dependências. Use no servidor: a chave não deve ir
para o navegador nem para o aplicativo do seu cliente.

## O que ele faz

| Método                   | Para quê                                                                         |
| ------------------------ | -------------------------------------------------------------------------------- |
| `validate($xmlOuArray)`  | confere uma DPS e devolve os problemas por severidade, com o código e a correção |
| `validateMany($notas)`   | o mesmo para um lote de até 50 notas                                             |
| `classify($entrada)`     | candidatos de CST e cClassTrib, com justificativa, alíquotas, reduções e fontes  |
| `tables()` `changelog()` | versões de tabela servidas e o que mudou em cada uma                             |
| `usage()`                | extrato do mês: quantas chamadas contaram e quantas não foram cobradas           |

O pacote **não tem regra tributária dentro**: tudo vem da API, e toda resposta traz `tables_version`, a
data da tabela usada. Quando a regra oficial muda, o seu código não muda. Guarde o `tables_version` junto
com o resultado.

## Validar antes de transmitir

`severity` é `blocking` (a nota não passa), `likely_rejection` (deve ser rejeitada) ou `warning`. `code` é
o código oficial da NFS-e nacional quando existe. O XML vai como `application/xml`; se você já monta a DPS
em array (`['DPS' => [...]]`), passe o array.

Nada do conteúdo da nota é guardado pela API: ela processa em memória e descarta.

## Sugerir CST e cClassTrib

```php
$r = $kalvi->classify([
    'service_code' => '04.01',
    'tax_regime' => 'LUCRO_PRESUMIDO',
    'municipality_code' => '4113700',
    'competence_date' => '2026-12-01',
]);
$melhor = $r['candidates'][0];
echo $melhor['cst'], ' ', $melhor['cClassTrib'], ' (tabela ', $r['tables_version'], ')', PHP_EOL;
```

## Erros

```php
use Kalvi\KalviError;
use Kalvi\KalviNetworkError;

try {
    $kalvi->validate($xml);
} catch (KalviError $e) {
    if ($e->isQuotaExceeded()) {
        echo 'Limite do mês. Zera em ', $e->resetsAt, '. Planos: ', $e->upgradeUrl, PHP_EOL;
    } else {
        echo $e->errorCode, ' ', $e->getMessage(), ' request_id: ', $e->requestId, PHP_EOL;
    }
} catch (KalviNetworkError $e) {
    echo 'A API não respondeu depois de ', $e->attempts, ' tentativas', PHP_EOL;
}
```

Guarde o `request_id`: é com ele que achamos a chamada no suporte, sem precisar da sua nota.

Falha nossa (5xx), rajada (`RATE_LIMITED`) e queda de rede são repetidas sozinhas, com espera crescente.
Erro de entrada e limite do mês **não**: repetir não conserta.

## Quanto já foi do plano

```php
$kalvi = new Kalvi\Kalvi([
    'onPlanUsage' => fn ($u) => error_log("Kalvi: {$u->used}/{$u->limit}, zera em {$u->resets}"),
]);
```

A API avisa a partir de 80% do limite. **Nunca cobramos excedente**: no limite a resposta é um erro claro
(`QUOTA_EXCEEDED`) com o endereço dos planos, e você sobe quando quiser.

## Opções

| Opção         | Padrão           | Para quê                                      |
| ------------- | ---------------- | --------------------------------------------- |
| `apiKey`      | `KALVI_API_KEY`  | sua chave                                     |
| `baseUrl`     | `KALVI_BASE_URL` | outra instância da API (staging, por exemplo) |
| `timeoutMs`   | `20000`          | tempo máximo por tentativa                    |
| `maxRetries`  | `2`              | tentativas extras                             |
| `onPlanUsage` | —                | aviso de consumo do plano                     |
| `userAgent`   | —                | identifica a sua aplicação nos nossos logs    |

## O que não é

Não emite, não assina e não transmite NFS-e: isso continua com o seu emissor. É conferência antes do envio.

Apoio técnico, não consultoria tributária. A responsabilidade pela nota é do emissor. Confira o resultado
com seu contador ou departamento fiscal.

MIT. O pacote é aberto; as regras ficam na API.
