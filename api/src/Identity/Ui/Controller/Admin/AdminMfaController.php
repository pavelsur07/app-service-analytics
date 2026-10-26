<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller\Admin;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdminMfaRepository;
use App\Identity\Domain\AdminMfaResult;
use App\Identity\Domain\AdminMfaState;
use App\Identity\Domain\AdminTotp;
use App\Identity\Ui\Request\AdminMfaCodeRequest;
use App\Identity\Ui\Response\Admin\AdminMfaCompletedResponse;
use App\Identity\Ui\Response\Admin\AdminMfaEnrollmentResponse;
use App\Identity\Ui\Response\Admin\AdminMfaStatusResponse;
use App\Identity\Ui\Security\AdminMfaSession;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/auth/mfa')]
#[OA\Response(response: 401, description: 'Требуется свежий вход по паролю', content: new Model(type: ValidationErrorResponse::class))]
#[OA\Response(response: 422, description: 'Код неверен или уже использован', content: new Model(type: ValidationErrorResponse::class))]
#[OA\Response(response: 429, description: 'Слишком много попыток MFA', content: new Model(type: ValidationErrorResponse::class))]
final readonly class AdminMfaController
{
    public function __construct(
        private Security $security,
        private AdminMfaRepository $mfa,
        private AdminMfaSession $session,
        private AdminTotp $totp,
        private ClockInterface $clock,
        #[Autowire(service: 'limiter.admin_mfa_account')]
        private RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.admin_mfa_ip')]
        private RateLimiterFactoryInterface $ipLimiter,
        private string $kernelSecret,
    ) {
    }

    #[Route('/status', name: 'identity_admin_mfa_status', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Состояние MFA-сессии', content: new Model(type: AdminMfaStatusResponse::class))]
    public function status(Request $request): JsonResponse
    {
        $context = $this->context($request);

        return null === $context ? $this->error(401, 'admin_mfa_required') : new JsonResponse(new AdminMfaStatusResponse($context[2]));
    }

    #[Route('/enroll', name: 'identity_admin_mfa_enroll', methods: ['POST'])]
    #[OA\Response(response: 200, description: 'Секрет для настройки authenticator; не кэшировать', content: new Model(type: AdminMfaEnrollmentResponse::class))]
    public function enroll(Request $request): JsonResponse
    {
        $context = $this->context($request);
        if (null === $context) {
            return $this->error(401, 'admin_mfa_required');
        }
        [$administrator, $state, $status] = $context;
        if ('enrollment_required' !== $status) {
            return $this->error(403, 'mfa_already_enabled');
        }
        $limited = $this->limit($request, $administrator);
        if (null !== $limited) {
            return $limited;
        }
        try {
            $enrollment = $this->mfa->beginEnrollment($administrator->id(), $state->version, $this->clock->now());
        } catch (\DomainException) {
            return $this->error(401, 'mfa_state_changed');
        }
        $this->session->enrollmentStarted($request, $enrollment->version);

        return $this->sensitive(new AdminMfaEnrollmentResponse($enrollment->secret, $this->totp->provisioningUri($enrollment->secret, $administrator->email())));
    }

    #[Route('/confirm', name: 'identity_admin_mfa_confirm', methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: AdminMfaCodeRequest::class))]
    #[OA\Response(response: 200, description: 'Фактор подключён, резервные коды показываются один раз', content: new Model(type: AdminMfaCompletedResponse::class))]
    public function confirm(Request $request): JsonResponse
    {
        return $this->complete($request, 'confirm');
    }

    #[Route('/verify', name: 'identity_admin_mfa_verify', methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: AdminMfaCodeRequest::class))]
    #[OA\Response(response: 200, description: 'Второй фактор подтверждён', content: new Model(type: AdminMfaCompletedResponse::class))]
    public function verify(Request $request): JsonResponse
    {
        return $this->complete($request, 'verify');
    }

    #[Route('/recovery-codes', name: 'identity_admin_mfa_recovery_codes', methods: ['POST'])]
    #[OA\RequestBody(content: new Model(type: AdminMfaCodeRequest::class))]
    #[OA\Response(response: 200, description: 'Новый набор кодов, прежний отменён', content: new Model(type: AdminMfaCompletedResponse::class))]
    public function recoveryCodes(Request $request): JsonResponse
    {
        return $this->complete($request, 'regenerate');
    }

    private function complete(Request $request, string $operation): JsonResponse
    {
        $context = $this->context($request);
        if (null === $context) {
            return $this->error(401, 'admin_mfa_required');
        }
        [$administrator, $state, $status] = $context;
        $expected = match ($operation) {
            'confirm' => 'enrollment_required',
            'verify' => 'verification_required',
            default => 'authenticated',
        };
        if ($status !== $expected) {
            return $this->error(422, 'mfa_state_changed');
        }
        $limited = $this->limit($request, $administrator);
        if (null !== $limited) {
            return $limited;
        }
        try {
            $code = AdminMfaCodeRequest::fromJson($request->getContent())->code;
        } catch (\InvalidArgumentException) {
            return $this->error(422, 'mfa_code_invalid');
        }
        $now = $this->clock->now();
        $result = match ($operation) {
            'confirm' => $this->mfa->confirmEnrollment($administrator->id(), $state->version, $code, $now),
            'verify' => $this->mfa->verify($administrator->id(), $state->version, $code, $now),
            default => $this->mfa->regenerateRecoveryCodes($administrator->id(), $state->version, $code, $now),
        };
        if (!$result instanceof AdminMfaResult) {
            return $this->error(422, 'mfa_code_invalid');
        }
        $this->session->verified($request, $administrator->id(), $result->version);

        return $this->sensitive(new AdminMfaCompletedResponse('authenticated', $result->recoveryCodes));
    }

    /** @return array{Administrator, AdminMfaState, string}|null */
    private function context(Request $request): ?array
    {
        $administrator = $this->security->getUser();
        if (!$administrator instanceof Administrator) {
            return null;
        }
        $state = $this->mfa->state($administrator->id());
        $status = $this->session->state($request, $administrator->id(), $state);

        return 'expired' === $status ? null : [$administrator, $state, $status];
    }

    private function limit(Request $request, Administrator $administrator): ?JsonResponse
    {
        $account = $this->accountLimiter->create(hash_hmac('sha256', 'admin-mfa:account:'.$administrator->id()->toRfc4122(), $this->kernelSecret))->consume();
        $ip = $this->ipLimiter->create(hash_hmac('sha256', 'admin-mfa:ip:'.($request->getClientIp() ?? ''), $this->kernelSecret))->consume();

        return $account->isAccepted() && $ip->isAccepted() ? null : $this->error(429, 'mfa_rate_limited');
    }

    private function error(int $status, string $code): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, match ($code) {
            'mfa_rate_limited' => 'Слишком много попыток. Повторите позже.',
            'mfa_code_invalid' => 'Код неверен, истёк или уже использован.',
            'mfa_already_enabled' => 'Второй фактор уже подключён.',
            default => 'Войдите по паролю и подтвердите второй фактор.',
        }), $status);
    }

    private function sensitive(AdminMfaEnrollmentResponse|AdminMfaCompletedResponse $body): JsonResponse
    {
        $response = new JsonResponse($body);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
