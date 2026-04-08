<?php

namespace App\Security;

use App\Service\RedisService;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authentication\Token\JWTUserToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

class JWTAuthenticator extends AbstractAuthenticator
{
    private const TOKEN_BLACKLIST_PREFIX = 'jwt_blacklist:';

    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly ?RedisService $redisService,
        private readonly ?AuthenticationSuccessHandlerInterface $successHandler,
        private readonly ?AuthenticationFailureHandlerInterface $failureHandler,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->getTokenFromRequest($request);
        
        if (!$token) {
            throw new CustomUserMessageAuthenticationException('Token not provided');
        }

        try {
            $decoded = $this->jwtManager->parse($token);
            
            // Check if token is blacklisted
            if (isset($decoded['jti']) && $this->redisService) {
                $blacklisted = $this->redisService->get(
                    self::TOKEN_BLACKLIST_PREFIX . $decoded['jti']
                );
                if ($blacklisted) {
                    throw new CustomUserMessageAuthenticationException('Token has been revoked');
                }
            }

            $user = $this->jwtManager->getUserFromPayload($decoded);
            
            return new Passport(
                new UserBadge($user->getUserIdentifier(), fn() => $user),
                new JWTUserToken($user->getRoles())
            );
        } catch (\Exception $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage() ?: 'Invalid token');
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?JsonResponse
    {
        if ($this->successHandler) {
            return $this->successHandler->onAuthenticationSuccess($request, $token);
        }
        
        return null;
    }

    public function onAuthenticationFailure(Request $request, \Exception $exception): ?JsonResponse
    {
        if ($this->failureHandler) {
            return $this->failureHandler->onAuthenticationFailure($request, $exception);
        }
        
        return new JsonResponse(['error' => $exception->getMessage()], 401);
    }

    private function getTokenFromRequest(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');
        
        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }
        
        return substr($authorization, 7);
    }
}