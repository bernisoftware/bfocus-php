<?php

declare(strict_types=1);

namespace Bfocus\Exception;

/** 409 — estado não permite a operação (`KB_ARTICLE_EMPTY`, `AI_DISABLED`, `IDEMPOTENCY_IN_PROGRESS`…). */
class ConflictException extends BfocusException
{
}
