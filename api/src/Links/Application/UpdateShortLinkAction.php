<?php

declare(strict_types=1);

namespace App\Links\Application;

use App\Identity\Application\Facade\IdentityAdminFacade;
use App\Links\Domain\ShortLink;
use App\Links\Domain\ShortLinkAuditAction;
use App\Links\Domain\ShortLinkRepository;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateShortLinkAction
{
    public function __construct(
        private ShortLinkRepository $links,
        private IdentityAdminFacade $identity,
    ) {
    }

    public function __invoke(
        string $linkId,
        string $name,
        string $targetUrl,
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

        $previous = self::describe($link);
        $at = new \DateTimeImmutable();
        if (!$link->changeDetails($name, $targetUrl, $at)) {
            return new ShortLinkMutationResult(ShortLinkMutationOutcome::Unchanged, $link);
        }

        $this->identity->recordAuditEntry(
            actorAdminId: $actorAdminId,
            action: ShortLinkAuditAction::DetailsChanged,
            subjectId: $linkId,
            previousValue: $previous,
            newValue: self::describe($link),
            occurredAt: $at,
        );

        try {
            $this->links->save();
        } catch (OptimisticLockException) {
            return new ShortLinkMutationResult(ShortLinkMutationOutcome::VersionConflict, null);
        }

        return new ShortLinkMutationResult(ShortLinkMutationOutcome::Saved, $link);
    }

    private static function describe(ShortLink $link): string
    {
        return json_encode(
            ['name' => $link->name(), 'targetUrl' => $link->targetUrl()],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );
    }
}
