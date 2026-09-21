<?php

declare(strict_types=1);

namespace App\Planning\Ui\Request;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class ReadDailyPlanRequest
{
    private function __construct(
        #[Assert\Type('string')]
        public mixed $from,
        #[Assert\Type('string')]
        public mixed $to,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query->all();
        if ([] !== array_diff(array_keys($query), ['from', 'to'])) {
            throw new \InvalidArgumentException('request_fields_invalid');
        }

        return new self($query['from'] ?? null, $query['to'] ?? null);
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ((null === $this->from) !== (null === $this->to)) {
            $context->buildViolation('period_incomplete')->addViolation();
        }
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    public function validPeriod(): array
    {
        if (null === $this->from) {
            $to = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));

            return [$to->modify('-29 days'), $to];
        }
        \assert(\is_string($this->from));
        \assert(\is_string($this->to));

        return [DailyPlanParameters::date($this->from), DailyPlanParameters::date($this->to)];
    }
}
