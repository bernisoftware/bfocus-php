# bFocus — SDK oficial para PHP

Integre seu sistema (ERP, site, pipeline de CI) ao **bFocus**: clientes, contatos, produtos,
release notes, base de conhecimento e agentes de IA pela API pública.

- PHP **8.1+**, sem dependências de runtime (só `ext-curl` e `ext-json`).
- Novas tentativas automáticas **seguras** (idempotência embutida em toda escrita).
- Erros tipados, com código estável e `request_id`.

## Instalação

```bash
composer require bfocus/bfocus:0.1.0
```

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$bf = new \Bfocus\Bfocus(getenv('BFOCUS_API_KEY'));

$cliente = $bf->customers->upsert('ERP 1042', [
    'name'  => 'Padaria Estrela',
    'email' => 'contato@padaria.example',
]);
echo $cliente['id'];
```

O cliente é identificado pelo **seu** código (`external_id` — o código do ERP, por exemplo). O
upsert cria na primeira vez e atualiza nas seguintes; rode quantas vezes quiser.

## Autenticação

Crie a chave no painel do bFocus em **Integrações → Chaves de API**, marcando só os escopos que a
integração usa. Ela vai em `Authorization: Bearer <chave>` — a SDK cuida disso.

| Escopo | Permite |
|---|---|
| `customers:read` / `customers:write` | Ler / gravar clientes, contatos, produtos vinculados e interações |
| `products:read` / `products:write` | Ler / gravar o catálogo de produtos |
| `release_notes:read` / `release_notes:write` | Ler / gravar e publicar release notes |
| `kb:read` / `kb:write` | Ler e buscar / gravar, publicar e excluir artigos da base de conhecimento |
| `ai_agents:read` | Ler os agentes de IA |
| `ai_agents:preview` | Testar a resposta de um agente (consome IA da conta) |

A chave legada (`bf_sk_…`) só alcança clientes (`customers:*`). Guarde a chave em variável de
ambiente, nunca no código.

### Opções do cliente

```php
$bf = new \Bfocus\Bfocus($apiKey, [
    'base_url'    => 'https://api.bfocus.com.br', // padrão; em dev: http://localhost:8000
    'timeout'     => 30,                          // segundos, por tentativa
    'max_retries' => 2,                           // novas tentativas além da primeira; 0 desliga
]);
```

Nenhuma chamada de rede acontece na construção.

## Convenções

- Argumentos obrigatórios são posicionais; os opcionais vão num **array associativo com as chaves
  da API** (`snake_case`).
- Nos upserts, **só o que você passa muda**: chave **ausente** = não enviada (o valor atual fica);
  chave com **`null`** = limpa o campo. Chave desconhecida gera `\InvalidArgumentException` (erro
  de digitação não passa em silêncio).
- Retornos são arrays associativos (o `data` da resposta, já desembrulhado). Campos novos da API
  aparecem como chaves novas — nunca quebram seu código.
- Listas paginadas devolvem `\Bfocus\Page` (`items`, `page`, `pageSize`, `total`, `pages`;
  iterável e contável). `listAll()` percorre todas as páginas sob demanda (um `Generator`).
- Datas em filtros aceitam `\DateTimeInterface` (enviada em UTC com `Z`) ou string ISO 8601.
- Todo método aceita, por último, `$options`: `['idempotency_key' => '...', 'timeout' => 10]`.

## Clientes — `$bf->customers`

```php
// Parcial: só "phone" muda — e null LIMPA o telefone.
$bf->customers->upsert('ERP 1042', ['phone' => null]);

// Campos customizados: quando enviados, SUBSTITUEM a lista inteira.
$bf->customers->upsert('ERP 1042', [
    'custom_fields' => [
        ['key' => 'plano', 'label' => 'Plano', 'value' => 'ouro'],
        ['key' => 'vencimento', 'label' => 'Vencimento', 'type' => 'date', 'value' => '2026-12-01'],
    ],
]);

$cliente = $bf->customers->get('ERP 1042');

$pagina = $bf->customers->list(['q' => 'padaria', 'page_size' => 50]);
foreach ($pagina as $c) { echo $c['name'], PHP_EOL; }
echo "{$pagina->total} clientes em {$pagina->pages} páginas\n";

// Sincronização incremental: tudo que mudou desde a última execução.
foreach ($bf->customers->listAll(['updated_since' => new DateTimeImmutable('-1 day')]) as $c) {
    // ...
}

$bf->customers->delete('ERP 1042');
```

### Contatos, produtos vinculados e interações

```php
$bf->customers->contacts->upsert('ERP 1042', 'CT-1', [
    'name' => 'Ana Souza', 'role' => 'Financeiro', 'email' => 'ana@padaria.example', 'is_primary' => true,
]);
$contatos = $bf->customers->contacts->list('ERP 1042');
$bf->customers->contacts->delete('ERP 1042', 'CT-1');

$bf->customers->products->attach('ERP 1042', 'erp-cloud');
$produtos = $bf->customers->products->list('ERP 1042');
$bf->customers->products->detach('ERP 1042', 'erp-cloud');

// Registro no histórico do cliente (HTML ou texto; nota interna por padrão).
$bf->customers->interactions->create('ERP 1042', 'Pedido 1042 faturado.', [
    'author_email' => 'carla@suaempresa.com.br', // usuário do bFocus que assina (omitido = "sistema")
    'is_internal'  => false,                     // visível ao cliente
]);
foreach ($bf->customers->interactions->listAll('ERP 1042') as $i) { /* ... */ }
```

## Produtos — `$bf->products`

```php
$bf->products->upsert('erp-cloud', ['name' => 'ERP Cloud', 'description' => 'Gestão na nuvem', 'color' => '#6366F1']);
$produtos = $bf->products->list(['include_inactive' => true]);
$produto  = $bf->products->get('erp-cloud');
$bf->products->archive('erp-cloud'); // arquiva (is_active = false); não apaga nada
```

## Release notes — `$bf->releaseNotes`

### Publicar a release note no CI

```php
// scripts/release-note.php — rode no pipeline depois do deploy da tag
$bf = new \Bfocus\Bfocus(getenv('BFOCUS_API_KEY'));
$versao = ltrim((string) getenv('CI_COMMIT_TAG'), 'v'); // "v2.3.0" → "2.3.0"

$nota = $bf->releaseNotes->upsert('erp-cloud', $versao, [
    'title'                => "Versão $versao",
    'description_markdown' => file_get_contents("docs/releases/$versao.md"),
    'audience'             => 'external', // internal | external | both
    'publish'              => true,       // publica depois de salvar (já publicada = nada muda)
]);
echo "Publicada em {$nota['published_at']}\n";
```

Rodar de novo com a mesma versão é seguro: é um upsert pela versão.

```php
$notas = $bf->releaseNotes->list('erp-cloud', ['published' => true]);
$nota  = $bf->releaseNotes->get('erp-cloud', '2.3.0');
$bf->releaseNotes->publish('erp-cloud', '2.4.0');
```

## Base de conhecimento — `$bf->kb`

### Sincronizar a base a partir de arquivos Markdown

`batchUpsert()` aceita **qualquer quantidade** de artigos: a SDK divide em lotes de 100 (o limite
da API), envia em sequência e devolve um resultado único (resultados na ordem, contadores somados).
Um artigo com problema não derruba os outros. Lista vazia devolve o resultado zerado sem requisição.

Cada lote é uma chamada própria, com a sua `Idempotency-Key`. Se você passar
`['idempotency_key' => 'sync-42']`, o 1º lote usa `sync-42` como veio e os seguintes `sync-42:2`,
`sync-42:3`… Se um lote inteiro falhar (erro HTTP), a exceção sobe e os lotes anteriores já foram
gravados — rodar de novo é seguro (upsert por `external_id`).

```php
$bf = new \Bfocus\Bfocus(getenv('BFOCUS_API_KEY'));

$artigos = [];
$arquivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('docs', FilesystemIterator::SKIP_DOTS));
foreach ($arquivos as $arquivo) {
    if ($arquivo->getExtension() !== 'md') {
        continue;
    }
    $caminho  = substr($arquivo->getPathname(), strlen('docs/'), -strlen('.md')); // "fiscal/nfse"
    $markdown = file_get_contents($arquivo->getPathname());
    preg_match('/^#\s+(.+)$/m', $markdown, $titulo);

    $artigos[] = [
        'external_id'   => 'git:' . str_replace('/', ':', $caminho), // external_id não aceita "/"
        'title'         => $titulo[1] ?? basename($caminho),
        'body_markdown' => $markdown,
        'product'       => 'erp-cloud', // null = artigo global (vale para todos os produtos)
    ];
}

$resultado = $bf->kb->articles->batchUpsert($artigos);
echo "criados {$resultado['created']}, atualizados {$resultado['updated']}, "
   . "sem mudança {$resultado['unchanged']}, falhas {$resultado['failed']}\n";

foreach ($resultado['results'] as $item) {
    if (!$item['ok']) {
        fwrite(STDERR, "{$item['external_id']}: {$item['error']}\n"); // ex.: KB_ARTICLE_TITLE_REQUIRED
    } elseif ($item['article']['status'] !== 'published') {
        $bf->kb->articles->publish($item['external_id']); // só publicado alimenta o agente de IA
    }
}

// Remove do bFocus o que saiu do repositório.
$locais = array_column($artigos, 'external_id');
foreach ($bf->kb->articles->listAll(['product' => 'erp-cloud']) as $artigo) {
    if (str_starts_with((string) $artigo['external_id'], 'git:') && !in_array($artigo['external_id'], $locais, true)) {
        $bf->kb->articles->delete($artigo['external_id']);
    }
}
```

Para publicar direto no lote, inclua `'status' => 'published'` em cada artigo.

### Artigo a artigo e busca

```php
$bf->kb->articles->upsert('notion:emitir-nfse', [
    'title'         => 'Como emitir NFS-e',
    'body_markdown' => "# Passo a passo\n\n1. Abra o menu **Fiscal**",
    'product'       => null,        // global
    'status'        => 'published', // draft | published
]);
$artigo = $bf->kb->articles->get('notion:emitir-nfse');
$bf->kb->articles->unpublish('notion:emitir-nfse');
$bf->kb->articles->delete('notion:emitir-nfse');

$pagina = $bf->kb->articles->list(['status' => 'published', 'q' => 'nota']);

// A mesma busca que alimenta o agente de IA (só artigos publicados).
$hits = $bf->kb->search('como emitir nota fiscal', ['product' => 'erp-cloud', 'limit' => 3]);
```

## Agentes de IA — `$bf->aiAgents`

```php
$agentes = $bf->aiAgents->list();
$agente  = $bf->aiAgents->get($agentes[0]['id']);

// Testa a resposta (nada é gravado; consome IA da conta).
$r = $bf->aiAgents->preview($agente['id'], 'Como emito uma NFS-e?', [
    'history' => [
        ['role' => 'customer', 'content' => 'Oi'],
        ['role' => 'bot', 'content' => 'Olá! Como posso ajudar?'],
    ],
]);
echo $r['action'];      // answer | handoff | refuse
echo $r['answer_html'];
```

## Erros

Toda resposta fora de 2xx vira uma exceção de `Bfocus\Exception\`, todas filhas de
`BfocusException`:

| Exceção | Quando |
|---|---|
| `AuthenticationException` | 401 — chave ausente, inválida ou revogada |
| `PermissionDeniedException` | 403 — chave desligada, IP não liberado ou escopo faltando (`getRequiredScope()`) |
| `NotFoundException` | 404 |
| `ConflictException` | 409 — ex.: `KB_ARTICLE_EMPTY`, `AI_DISABLED` |
| `ValidationException` | 422 — detalhe por campo em `getValidation()` |
| `RateLimitException` | 429 — `getRetryAfter()` em segundos |
| `ServerException` | 5xx |
| `NetworkException` | falha de conexão ou timeout (`getStatus()` = 0, código `NETWORK_ERROR`) |
| `BfocusException` | qualquer outro status; e 2xx sem envelope JSON válido (HTML de proxy, corpo vazio…) com código `INVALID_RESPONSE` |

**Na sua lógica, use `getErrorCode()`** — é o código estável da API (`CUSTOMER_NOT_FOUND`,
`VALIDATION_ERROR`…). A mensagem é para humanos e pode mudar.

```php
use Bfocus\Exception\BfocusException;
use Bfocus\Exception\ValidationException;

try {
    $bf->customers->contacts->upsert('ERP 1042', 'CT-2', ['name' => 'Beto', 'email' => 'nao-e-email']);
} catch (ValidationException $e) {
    print_r($e->getValidation()); // ['email' => 'value is not a valid email address']
} catch (BfocusException $e) {
    if ($e->getErrorCode() === 'CUSTOMER_NOT_FOUND') {
        // ...
    }
    error_log("bFocus {$e->getErrorCode()} (HTTP {$e->getStatus()}) request_id={$e->getRequestId()}");
}
```

Ao falar com o suporte, informe o `getRequestId()`. Ele vem do corpo do erro, senão do header
`X-Request-Id` da resposta, senão é o `X-Request-Id` que a própria SDK enviou (a API o ecoa, então
bate com os logs) — por isso existe até em `NetworkException` e em `INVALID_RESPONSE`.

Argumento inválido do seu lado gera `\InvalidArgumentException` antes de qualquer requisição: chave
de API vazia, parâmetro desconhecido, parâmetro de caminho (`external_id`, slug, versão…) vazio,
`"."` ou `".."` (viram outro caminho no cURL e nos proxies, mesmo codificados) e `external_id` de
artigo com `/`.

### Novas tentativas e idempotência

A SDK tenta de novo sozinha (até `max_retries`, padrão 2) em erro de rede/timeout e nos status
`429`, `502`, `503` e `504` — nunca em `500` ou 4xx. A espera respeita o `Retry-After` (até 60 s);
sem ele, cresce exponencialmente (0,5 s, 1 s, 2 s… até 8 s, com variação aleatória).

Toda escrita (POST/PUT/DELETE) leva uma `Idempotency-Key`, a **mesma** em todas as tentativas: se a
primeira chegou a gravar e só a resposta se perdeu, a API devolve a resposta original em vez de
gravar de novo. Para amarrar a idempotência a um evento seu (ex.: reprocessamento de fila), passe a
sua chave:

```php
$bf->customers->interactions->create('ERP 1042', 'Pedido 1042 faturado.', [], [
    'idempotency_key' => 'pedido-1042-faturado',
]);
```

## Identidade do widget

Para o widget de atendimento reconhecer o usuário logado no seu sistema, assine a identidade **no
seu backend** (sem rede e sem chave de API) com o segredo do widget:

```php
$assinatura = \Bfocus\WidgetIdentity::sign(
    getenv('BFOCUS_WIDGET_SECRET'),
    $usuario->id,        // userExternalId
    $empresa->codigoErp, // customerExternalId (o external_id do cliente no bFocus)
);
// entregue userExternalId, customerExternalId e $assinatura ao front, que os passa ao widget
```

É um HMAC-SHA256 (hex minúsculo) de `v1:<userExternalId>:<customerExternalId>`. Nunca exponha o
segredo no navegador.

## Versão

Fixe a **versão exata** no `composer.json` e suba de propósito:

```json
{ "require": { "bfocus/bfocus": "0.1.0" } }
```

Cada release declara se muda a superfície pública (assinaturas, formatos, comportamento) ou se é
só aditiva. A SDK se identifica em toda requisição (`X-Bfocus-Client: bfocus-php/0.1.0`) — é assim
que o bFocus avisa quando uma correção exige atualizar.

## Desenvolvimento

```bash
composer install
vendor/bin/phpunit        # conformidade (tests/conformance/cases.json) + unitários
BFOCUS_API_KEY=bf_live_... php examples/quickstart.php
```

## Licença

MIT © Berni Software
