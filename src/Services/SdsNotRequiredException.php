<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * Thrown when an SDS, alias sheet or label is requested for a raw material
 * marked "No SDS required" (migration 064). A RuntimeException, so existing
 * catch (\Throwable) callers keep working; callers that should skip such an
 * item quietly (publish worker, shipped-SDS export, auto-send) catch this
 * subclass first.
 */
final class SdsNotRequiredException extends \RuntimeException
{
}
