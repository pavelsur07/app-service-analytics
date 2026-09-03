<?php

declare(strict_types=1);

namespace App\Links\Application;

use App\Identity\Application\Facade\IdentityAdminFacade;
use App\Links\Domain\ShortLinkAuditAction;
use App\Links\Domain\ShortLinkRepository;
use App\Links\Domain\ShortLinkStatus;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeShortLinkStatusAction
{
    public function __construct(
        private ShortLinkRepository $links,
        private IdentityAdminFacade $identity,
    ) {
    }

    public function __invoke(
        string $linkId,
        ShortLinkStatus $status,
        int $expectedVersion,
        string $actorAdminId,
    ): ShortLinkMutationResult {
        $link = $this->links->get(Uuid::fromString($linkId));
        if (null === $link) {
            return new ShortLinkMutationResult(ShortLinkMutationOutcome::NotFound, null);
        }

        if ($link->version() !== $expectedVersion) {
            return new ShortLinkMutationResult(ShortLinkMutationOutcome::VersionConflict, null);
        }

        $previous = $link->status()->value;
        $at = new \DateTimeImmutable();
        if (!$link->changeStatus($status, $at)) {
            return new ShortLinkMutationResult(ShortLinkMutationOutcome::Unchanged, $link);
        }

        $this->identity->recordAuditEntry(
            actorAdminId: $actorAdminId,
            action: ShortLinkStatus::Active === $status
                ? ShortLinkAuditAction::Activated
                : ShortLinkAuditAction::Disabled,
            subjectId: $linkId,
            previousValue: $previous,
            newValue: $status->value,
            occurredAt: $at,
        );

        try {
            $this->links->save();
        } catch (OptimisticLockException) {
            return new ShortLinkMutationResult(ShortLinkMutationOutcome::VersionConflict, null);
        }

        return new ShortLinkMutationResult(ShortLinkMutationOutcome::Saved, $link);
    }
}
