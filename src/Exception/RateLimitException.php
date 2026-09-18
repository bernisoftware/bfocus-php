<?php

declare(strict_types=1);

namespace Bfocus\Exception;

/** 429 — limite de requisições da chave (já esgotadas as novas tentativas); veja `getRetryAfter()`. */
class RateLimitException extends BfocusException
{
}
