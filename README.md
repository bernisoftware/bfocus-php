# bFocus — SDK oficial para PHP

Integre seu sistema (ERP, site, pipeline de CI) ao **bFocus**: clientes, pessoas, contatos,
produtos, release notes, base de conhecimento e agentes de IA pela API pública — com lotes de até
500 e identificadores extras para sincronizar a sua base.

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
| `customers:read` / `customers:write` | Ler / gravar clientes, pessoas, contatos, produtos vinculados, interações, lotes e identificadores extras |
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

### Identificadores extras — `$bf->customers->identifiers`

Liga o id de **outro sistema seu** (ex.: o código do CRM) ao mesmo cadastro, que continua com o
`external_id` principal. Depois disso, chamar com o id extra acha o mesmo cliente. É idempotente;
se o id já pertence a outro cadastro, vem `ConflictException` com código `IDENTIFIER_IN_USE`.

```php
$c = $bf->customers->identifiers->add('erp-1042', 'crm-88', ['label' => 'CRM']); // label é opcional
print_r($c['identifiers']); // [['external_id' => 'crm-88', 'label' => 'CRM', 'source' => 'api']]
$bf->customers->identifiers->remove('erp-1042', 'crm-88');
```

## Pessoas — `$bf->people`

As pessoas do cliente: quem abre chamado, usa o widget e recebe e-mail. A pessoa é identificada
pelo **seu** id do usuário — o mesmo `userExternalId` que você assina para o widget (por isso não
pode ter `:`). Escopos `customers:read` / `customers:write`.

```php
$p = $bf->people->upsert('erp-1042', 'app-77', [
    'name'       => 'Paula Reis',
    'email'      => 'paula@padaria.example',
    'role'       => 'Financeiro',
    'is_primary' => true,
    // 'extra_emails' => ['paula.reis@pessoal.example'], // somam aos que já existem
]);
echo $p['status']; // "created", "updated" ou "unchanged"

$pessoas = $bf->people->list('erp-1042');       // com e sem acesso
$bf->people->delete('erp-1042', 'app-77');       // retira o acesso (devolve a pessoa com access = false)
$bf->people->upsert('erp-1042', 'app-77', ['access' => true]); // devolve o acesso
```

- **Nunca duplica**: o e-mail (ou o telefone) acha a pessoa que já chegou por e-mail ou por outro
  sistema, e ela é adotada com o seu id. A mesma pessoa informada com **outro cliente** é
  transferida para ele.
- `delete()` **retira o acesso**: a pessoa continua no histórico dos chamados; um `upsert()` com
  `'access' => true` devolve o acesso.
- Parcial como os outros upserts: só as chaves presentes mudam.

### Campos personalizados da pessoa

`custom_fields` leva o que só existe no seu sistema (matrícula, centro de custo, filial). É a
**exceção** ao "só o que vier muda": a lista enviada **substitui a lista inteira** — campo que
ficar de fora é **removido**. Mande sempre a lista que o seu sistema tem hoje; omitir a chave não mexe
em nada, como em qualquer outro campo.

A `visibility` é decidida no bFocus e **preservada entre sincronizações** — por isso ela não vai
no envio, só volta na resposta: o seu ERP não rebaixa nem promove a exposição de um dado sem
querer.

Vale no upsert de pessoa, no lote de pessoas e na listagem de pessoas do cliente.

```php
$p = $bf->people->upsert('erp-1042', 'app-77', [
    'custom_fields' => [                 // a lista INTEIRA do seu sistema
        ['key' => 'matricula', 'label' => 'Matrícula', 'value' => '4471'],
        ['key' => 'filial',    'label' => 'Filial',    'value' => 'Centro'],
    ],
]);
foreach ($p['custom_fields'] as $campo) {
    echo $campo['key'], ' ', $campo['value'], ' ', $campo['visibility'], PHP_EOL; // visibility vem do bFocus
}
```

### Apagar o e-mail ou o telefone da pessoa

Um contato gravado errado ficava preso para sempre: enquanto a ficha errada segurasse o
telefone, nenhum reenvio o soltava. `clear` apaga.

```php
$bf->people->upsert('erp-1042', 'app-77', ['clear' => ['phone']]);            // some o telefone
$bf->people->upsert('erp-1042', 'app-77', ['clear' => ['email', 'phone']]);   // some os dois
```

Três regras que parecem contraintuitivas e são de propósito:

- **Apagar é explícito.** `'phone' => null`, `'clear' => []` e omitir a chave continuam
  significando **"não mexe"** — a SDK não traduz `null` em `clear`. Fazer o `null` apagar teria
  apagado, em silêncio e na primeira carga seguinte, o dado de todo sistema que manda `null`
  para "não tenho esse valor".
- **Campo fora da lista é recusado, não ignorado**: hoje só `'email'` e `'phone'`; qualquer
  outro devolve 422 `PERSON_CLEAR_FIELD_INVALID` (`ValidationException`).
- **Só se limpa a própria ficha.** Se você alcançou a pessoa por um identificador **extra**, a
  API recusa com 409 `PERSON_CLEAR_NOT_OWN_RECORD` (`ConflictException`): apagar o contato de
  uma ficha alcançada por apelido seria apagar dado de outro sistema. Para saber se o id que
  você tem em mãos é o principal ou um extra, use `$bf->people->identifiers->list(...)`.

Vale no `people->upsert()` e no `people->batch()` (`'clear' => ['phone']` no item).

### Contato já usado: um 409 que você consegue resolver

`PERSON_EMAIL_TAKEN` e `PERSON_PHONE_TAKEN` (409) não são "tente de novo": o e-mail (ou o
telefone) já é de outra pessoa da conta. O erro diz **de quem**, em `getData()` (a API repete o mesmo
detalhe em `getValidation()`, por compatibilidade):

| campo | o que é |
| --- | --- |
| `field` | `email` ou `phone` — qual contato está tomado |
| `owner_external_id` | o identificador da pessoa que já usa esse contato |
| `owner_name` | o nome dela |
| `owner_customer_external_id` | o cliente a que ela pertence |

**É o `owner_customer_external_id` que decide a ação**, e os dois casos pedem coisas opostas:

- **mesmo cliente que você enviou** → é quase sempre a MESMA pessoa em dois sistemas. Uma pessoa
  tem **N identificadores**: registre o seu como **extra** dela. A partir daí o seu id encontra
  essa pessoa.
- **outro cliente** → ninguém decide sozinho a quem a pessoa pertence. Não force: registre o caso
  e leve para quem conhece o cadastro. Unificar dois clientes é decisão de gente, não de um
  casamento por e-mail.

```php
use Bfocus\Exception\ConflictException;

try {
    $bf->people->upsert('erp-1042', 'app-77', ['name' => 'Paula Reis', 'email' => 'paula@padaria.example']);
} catch (ConflictException $e) {
    if (!in_array($e->getErrorCode(), ['PERSON_EMAIL_TAKEN', 'PERSON_PHONE_TAKEN'], true)) {
        throw $e;
    }
    $dono = $e->getData();
    if (($dono['owner_customer_external_id'] ?? null) === 'erp-1042') {
        // A mesma pessoa, com dois ids: o seu vira mais um identificador dela.
        $bf->people->identifiers->add($dono['owner_external_id'], 'app-77', ['label' => 'ERP']);
    } else {
        // Dono em OUTRO cliente: não decida sozinho — registre e leve para o cadastro.
        avisarCadastro($e->getErrorCode(), $dono);
    }
}
```

`PERSON_CONTACT_OTHER_CUSTOMER` (409) é o mesmo assunto pelo outro lado, e é **recusa
definitiva**: a API não move mais uma pessoa de um cliente para outro só porque o e-mail (ou o
telefone) casou. Repetir a chamada não resolve — trate como caso para o cadastro, nunca como
falha temporária.

Identificadores extras da pessoa funcionam como os do cliente:

```php
$bf->people->identifiers->add('app-77', 'crm-p5', ['label' => 'CRM']);
$bf->people->identifiers->remove('app-77', 'crm-p5');
```

### Ler os identificadores da pessoa (para reconciliar)

`$bf->people->list(...)` mostra só o identificador **principal** de cada pessoa. Quando dois
cadastros seus eram a mesma pessoa, um dos ids virou **extra** — e some da listagem sem ter
sumido do cadastro. É isso que faz a sua conferência fechar "633 de 636" sem explicar os 3.

`identifiers->list()` é a fonte de verdade dessa conferência, e é **leitura**: antes dela era
preciso ESCREVER (tentar um `add()`) para descobrir o que tinha acontecido. Aceita no caminho o
id principal **ou qualquer um dos extras**.

```php
$ids = $bf->people->identifiers->list('crm-p5'); // o id extra que "sumiu" da listagem
echo $ids['external_id'], PHP_EOL;               // 'app-77' — o principal do cadastro
foreach ($ids['identifiers'] as $i) {
    echo $i['external_id'], ' ', $i['label'] ?? '-', ' ', $i['source'], PHP_EOL;
}
```

## Lotes — `customers->batch()` e `people->batch()`

Até **500 itens por chamada** (`Customers::BATCH_MAX` / `People::BATCH_MAX`). Acima disso a SDK
lança `\InvalidArgumentException` **antes** de qualquer requisição — ela **não** divide sozinha,
porque o `index` de cada resultado é a posição no lote enviado. Divida você:

```php
use Bfocus\Resources\Customers;

// $clientes: cada item com os campos do customers->upsert() + 'external_id'
foreach (array_chunk($clientes, Customers::BATCH_MAX) as $fatia) {
    $r = $bf->customers->batch($fatia);
    foreach ($r['results'] as $item) {
        if ($item['status'] === 'error') {
            error_log("cliente {$fatia[$item['index']]['external_id']}: {$item['error']} (HTTP {$item['code']})");
        }
    }
}

// Pessoas: 'customer_external_id' + 'external_id' da pessoa + os campos do people->upsert()
$r = $bf->people->batch([
    ['customer_external_id' => 'erp-1042', 'external_id' => 'app-77', 'name' => 'Paula Reis', 'email' => 'paula@padaria.example'],
    ['customer_external_id' => 'erp-1043', 'external_id' => 'app-78', 'name' => 'Rui Lima'],
]);
print_r($r['summary']); // ['created' => 1, 'updated' => 0, 'unchanged' => 0, 'error' => 1]
```

Cada resultado traz `index`, `status` (`created`, `updated`, `unchanged` ou `error`),
`external_id`, `merged_into` (o id enviado era um identificador extra: este é o principal do
cadastro), `error` (código estável) e `code` (o status HTTP que o item teria sozinho). **Um item com
erro não desfaz os outros** — confira `summary['error']`. Lista vazia devolve o resultado zerado
sem fazer requisição. O lote inteiro é uma chamada: aceita `['idempotency_key' => ...]` como
qualquer escrita.

## Sincronizar clientes e usuários do seu sistema

**Ids com o prefixo do sistema, sem `:`** — use `-` como separador (`erp-1042` para clientes,
`app-77` para pessoas) ou UUIDs puros. Assim vários sistemas seus convivem no mesmo bFocus sem
colisão. O id da pessoa é o `userExternalId` assinado no widget, e a assinatura recusa `:`.

**Carga inicial (no deploy):** clientes em fatias de 500 → ligue cada cliente ao produto → pessoas
em fatias de 500. Confira `summary['error']` e registre os itens com erro.

```php
use Bfocus\Resources\Customers;
use Bfocus\Resources\People;

foreach (array_chunk($clientes, Customers::BATCH_MAX) as $fatia) {
    $r = $bf->customers->batch($fatia);
    if ($r['summary']['error'] > 0) { /* registre os itens com status "error" */ }
}
foreach ($clientes as $c) {
    $bf->customers->products->attach($c['external_id'], 'erp-cloud'); // idempotente
}
foreach (array_chunk($usuarios, People::BATCH_MAX) as $fatia) {
    $r = $bf->people->batch($fatia);
    if ($r['summary']['error'] > 0) { /* idem */ }
}
```

**Depois, no dia a dia**, espelhe cada evento do seu sistema:

| No seu sistema | Chamada |
|---|---|
| criou/alterou cliente | `$bf->customers->upsert($id, [...])` + `$bf->customers->products->attach($id, $slug)` |
| criou/alterou usuário | `$bf->people->upsert($clienteId, $usuarioId, [...])` |
| excluiu/desativou usuário | `$bf->people->delete($clienteId, $usuarioId)` |
| excluiu cliente | `$bf->customers->delete($id)` |

Se um resultado trouxer `merged_into`, o cadastro foi unificado: atualize o id do seu lado.

**Nunca bloqueie a requisição do seu usuário esperando o bFocus.** Enfileire (job, tabela de
outbox) e processe em segundo plano, tentando de novo com espera crescente. A SDK já repete
429/5xx com a mesma `Idempotency-Key`; a fila cobre indisponibilidades longas.

```php
// no request do seu usuário: só enfileira
$fila->push('bfocus.pessoa', ['cliente' => $empresa->codigo, 'usuario' => $usuario->id]);

// no worker
function sincronizarPessoa(\Bfocus\Bfocus $bf, array $job, Usuario $u): void
{
    // Exceção (rede fora, 5xx, 429 persistente) sobe: a fila tenta de novo mais tarde, com backoff.
    $bf->people->upsert("erp-{$job['cliente']}", "app-{$job['usuario']}", [
        'name' => $u->nome, 'email' => $u->email, 'access' => $u->ativo,
    ]);
}
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

Além de `getErrorCode()`, `getStatus()`, `getRequestId()`, `getValidation()`, `getRetryAfter()` e
`getRequiredScope()`, a exceção tem **`getData()`**: o `data` do corpo, com o detalhe estruturado
que alguns erros trazem (`[]` quando não há). É por ele que um 409 de contato tomado diz de **quem**
é o contato — veja [Pessoas](#pessoas--bf-people).

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

### Identidade v2 (com validade)

A v2 carimba o instante: a API aceita a assinatura de **7 dias atrás até 5 minutos à frente**, então
uma assinatura vazada deixa de valer sozinha. Gere **a cada renderização da página** — nunca guarde.
Ela vai no mesmo lugar da v1 (a v1 continua aceita).

```php
$assinatura = \Bfocus\WidgetIdentity::signV2(
    getenv('BFOCUS_WIDGET_SECRET'),
    'app-77',   // userExternalId — não pode conter ":" (é o separador)
    'erp-1042', // customerExternalId
);
// "v2.1789000000.3f9a…": v2.<segundos unix>.<HMAC-SHA256 hex de "v2:<ts>:<user>:<customer>">
```

O 4º argumento opcional fixa o instante: `int` em segundos unix (não milissegundos) ou
`\DateTimeInterface`. Segredo vazio, `:` no id do usuário ou instante negativo geram
`\InvalidArgumentException`.

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
