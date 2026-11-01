<?php

namespace Proclame\ClubPlanner;

use RuntimeException;
use Throwable;

class ClubPlannerException extends RuntimeException
{
    /**
     * The HTTP status the Partner API answered with, when the failure came
     * from the API at all. Null for a local failure such as a missing key, an
     * unreachable host or an unreadable date.
     *
     * The status carries meaning the message does not: a 403 is a permission
     * problem on the key rather than a bad request, and a 429 means the call
     * was refused before it ran.
     */
    public function __construct(
        string $message = '',
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The key is missing, invalid, expired or deactivated.
     */
    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /**
     * The key lacks access to the namespace, or is limited to another club.
     * Not something a different set of arguments would fix.
     */
    public function isForbidden(): bool
    {
        return $this->status === 403;
    }

    /**
     * The record does not exist, or is out of scope for the key.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * GET /members/find answers 409 when a name or mobile matches more than
     * one member, rather than picking one.
     */
    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    /**
     * The key's rate limit is spent. Retrying now will fail the same way.
     */
    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
