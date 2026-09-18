<?php

declare(strict_types=1);

namespace Bfocus\Resources;

use Bfocus\Exception\BfocusException;

/**
 * Agentes de IA — `$bf->aiAgents`. Escopos `ai_agents:read` / `ai_agents:preview`.
 *
 * @phpstan-import-type RequestOptions from AbstractResource
 * @phpstan-import-type ProductRef from CustomerProducts
 *
 * @phpstan-type AiAgent array{
 *     id: string,
 *     name: string,
 *     product: ProductRef,
 *     active: bool,
 *     persona: string|null,
 *     scope: string|null,
 *     avatar_url: string|null,
 *     created_at: string|null,
 *     updated_at: string|null
 * }
 * @phpstan-type AiAgentTurn array{role: 'customer'|'bot', content: string}
 * @phpstan-type AiAgentPreviewParams array{history?: list<AiAgentTurn>}
 * @phpstan-type AiAgentPreview array{
 *     action: string,
 *     answer_html: string|null,
 *     escalated: bool,
 *     refused: bool,
 *     handoff_reason: string|null,
 *     guards: list<string>,
 *     topic: string|null,
 *     confidence: float|null,
 *     citations: list<mixed>,
 *     collected: array<string, mixed>,
 *     missing: list<mixed>,
 *     sources: list<array<string, mixed>>
 * }
 */
final class AiAgents extends AbstractResource
{
    private const PREVIEW_FIELDS = ['history'];

    /**
     * @param RequestOptions $options
     * @return list<AiAgent>
     * @throws BfocusException
     */
    public function list(array $options = []): array
    {
        return $this->call('GET', '/ai-agents', [], null, $options);
    }

    /**
     * @param RequestOptions $options
     * @return AiAgent
     * @throws BfocusException
     */
    public function get(string $agentId, array $options = []): array
    {
        return $this->call('GET', '/ai-agents/' . self::segment($agentId, 'agent_id'), [], null, $options);
    }

    /**
     * Testa a resposta do agente a uma mensagem (consome IA do tenant; nada é gravado).
     * `action`: `answer`, `handoff` (transferiria a um humano) ou `refuse`.
     *
     * ```php
     * $r = $bf->aiAgents->preview($agentId, 'Como emito uma NFS-e?', [
     *     'history' => [['role' => 'customer', 'content' => 'Oi'], ['role' => 'bot', 'content' => 'Olá!']],
     * ]);
     * ```
     *
     * @param AiAgentPreviewParams $params
     * @param RequestOptions $options
     * @return AiAgentPreview
     * @throws BfocusException `ConflictException` com `AI_DISABLED` se a IA estiver desligada.
     */
    public function preview(string $agentId, string $message, array $params = [], array $options = []): array
    {
        $body = ['message' => $message] + self::only($params, self::PREVIEW_FIELDS, 'aiAgents->preview');

        return $this->call('POST', '/ai-agents/' . self::segment($agentId, 'agent_id') . '/preview', [], $body, $options);
    }
}
