<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts\Exception;

final class BudgetExceededException extends JarvisException
{
    public function __construct(public readonly string $budgetCode, string $safeMessage)
    {
        parent::__construct($safeMessage);
    }
}
