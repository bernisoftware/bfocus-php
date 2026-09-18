<?php

declare(strict_types=1);

/**
 * Quickstart da SDK PHP do bFocus.
 *
 *   composer install
 *   BFOCUS_API_KEY=bf_live_... php examples/quickstart.php
 *
 * Em dev, aponte para a API local: BFOCUS_BASE_URL=http://localhost:8000
 */

require __DIR__ . '/../vendor/autoload.php';

use Bfocus\Bfocus;
use Bfocus\Exception\BfocusException;
use Bfocus\Exception\NotFoundException;

$apiKey = getenv('BFOCUS_API_KEY');
if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Defina BFOCUS_API_KEY (Integrações → Chaves de API no painel do bFocus).\n");
    exit(1);
}

$options = [];
$baseUrl = getenv('BFOCUS_BASE_URL');
if (is_string($baseUrl) && $baseUrl !== '') {
    $options['base_url'] = $baseUrl;
}
$bf = new Bfocus($apiKey, $options);

try {
    // 1) Upsert de cliente pelo SEU código (idempotente: rode quantas vezes quiser)
    $cliente = $bf->customers->upsert('ERP 1042', [
        'name' => 'Padaria Estrela',
        'email' => 'contato@padaria.example',
        'custom_fields' => [['key' => 'plano', 'label' => 'Plano', 'value' => 'ouro']],
    ]);
    echo "Cliente: {$cliente['name']} ({$cliente['id']})\n";

    // 2) Um contato e uma nota no histórico
    $bf->customers->contacts->upsert('ERP 1042', 'CT-1', ['name' => 'Ana Souza', 'is_primary' => true]);
    $bf->customers->interactions->create('ERP 1042', 'Cliente cadastrado pelo quickstart da SDK PHP.');

    // 3) Produtos do catálogo
    foreach ($bf->products->list() as $produto) {
        echo "Produto: {$produto['slug']} — versão atual {$produto['current_version']}\n";
    }

    // 4) Busca na base de conhecimento
    foreach ($bf->kb->search('como emitir nota fiscal', ['limit' => 3]) as $hit) {
        echo "KB: {$hit['title']}\n";
    }

    // 5) Todos os clientes, página a página (sob demanda)
    $total = 0;
    foreach ($bf->customers->listAll(['page_size' => 100]) as $c) {
        $total++;
    }
    echo "Clientes na conta: {$total}\n";

    // 6) Erro tratado pelo code (estável), não pela mensagem
    try {
        $bf->customers->get('nao-existe');
    } catch (NotFoundException $e) {
        echo "Esperado: {$e->getErrorCode()} (request_id {$e->getRequestId()})\n";
    }
} catch (BfocusException $e) {
    fwrite(STDERR, sprintf("Erro %s (HTTP %d, request_id %s): %s\n", $e->getErrorCode(), $e->getStatus(), $e->getRequestId() ?? '-', $e->getMessage()));
    if ($e->getRequiredScope() !== null) {
        fwrite(STDERR, "A chave precisa do escopo {$e->getRequiredScope()}.\n");
    }
    exit(1);
}
