<?php

declare(strict_types=1);

namespace Bfocus\Exception;

/**
 * 403 — chave desligada, IP não liberado ou escopo faltando (`INTEGRATION_SCOPE_MISSING`;
 * o escopo exigido vem em `getRequiredScope()`).
 */
class PermissionDeniedException extends BfocusException
{
}
