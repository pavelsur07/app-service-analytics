<?php

declare(strict_types=1);

namespace App\Links\Ui\Controller;

use App\Links\Domain\BotDetector;
use App\Links\Domain\ShortLinkClick;
use App\Links\Domain\ShortLinkClickRepository;
use App\Links\Infrastructure\Query\ActiveShortLinkQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/{code}',
    name: 'links_redirect',
    host: 'lin.conwix.{suffix}',
    requirements: [
        'code' => '[0-9A-Za-z]{7}',
        'suffix' => 'com|localhost|internal',
    ],
    methods: ['GET'],
)]
final readonly class RedirectShortLinkController
{
    public function __construct(
        private ActiveShortLinkQuery $links,
        private ShortLinkClickRepository $clicks,
        private BotDetector $bots,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $code, Request $request): RedirectResponse
    {
        $target = $this->links->find($code);
        if (null === $target) {
            throw new NotFoundHttpException();
        }

        $userAgent = self::header($request->headers->get('User-Agent'), 1024);
        $referer = self::header($request->headers->get('Referer'), 2048);

        try {
            $this->clicks->record(ShortLinkClick::record(
                Uuid::fromString($target->id),
                new \DateTimeImmutable(),
                $userAgent,
                $referer,
                $this->bots->isBot($userAgent),
            ));
        } catch (\Throwable $failure) {
            // request_id добавляет общий RequestContextProcessor.
            $this->logger->warning('Short link click was not recorded.', [
                'link_id' => $target->id,
                'code' => $target->code,
                'exception' => $failure,
            ]);
        }

        $response = new RedirectResponse($target->targetUrl, Response::HTTP_FOUND);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private static function header(?string $value, int $limit): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return mb_substr(trim($value), 0, $limit);
    }
}
