<?php

namespace App\Advisor;

/**
 * Thrown when the advisor is asked to run without a configured ANTHROPIC_API_KEY.
 */
final class AdvisorUnavailableException extends \RuntimeException
{
}
