<?php

namespace App\EventSubscriber;

use App\Service\RateLimitService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RateLimitSubscriber implements EventSubscriberInterface
{
    // Default limits per endpoint type
    private const LIMITS = [
        'api_login' => ['limit' => 10, 'window' => 60],      // 10 login attempts per minute
        'api_register' => ['limit' => 5, 'window' => 300],   // 5 registrations per 5 minutes
        'api_rooms_create' => ['limit' => 20, 'window' => 60], // 20 room creates per minute
        'api_rooms' => ['limit' => 60, 'window' => 60],     // 60 requests per minute default
        'api_chat' => ['limit' => 30, 'window' => 60],      // 30 chat messages per minute
        'api_analytics' => ['limit' => 30, 'window' => 60], // 30 analytics requests per minute
    ];

    public function __construct(
        private readonly RateLimitService $rateLimitService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        
        // Skip non-API routes
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        // Skip routes without authentication requirement (login, register)
        $route = $request->attributes->get('_route');
        if (!$route) {
            return;
        }

        // Determine limit based on route
        $limitConfig = $this->getLimitForRoute($route);
        if (!$limitConfig) {
            return;
        }

        // Get user ID if authenticated
        $userId = null;
        $token = $request->attributes->get('security_token');
        if ($token && $token->getUser()) {
            $user = $token->getUser();
            if (method_exists($user, 'getId')) {
                $userId = (string) $user->getId();
            }
        }

        // Build rate limit key
        $identifier = $this->rateLimitService->getIdentifier($request, $userId);
        $key = "{$identifier}:{$route}";

        // Check rate limit
        if (!$this->rateLimitService->check($key, $limitConfig['limit'], $limitConfig['window'])) {
            $event->setResponse(
                new \Symfony\Component\HttpFoundation\JsonResponse(
                    ['error' => 'Rate limit exceeded. Please try again later.'],
                    \Symfony\Component\HttpFoundation\Response::HTTP_TOO_MANY_REQUESTS
                )
            );
        }

        // Add rate limit headers to response
        $response = $event->getResponse();
        if ($response) {
            $rateLimit = $this->rateLimitService->getRateLimit($key, $limitConfig['limit'], $limitConfig['window']);
            $response->headers->set('X-RateLimit-Limit', (string) $rateLimit['limit']);
            $response->headers->set('X-RateLimit-Remaining', (string) $rateLimit['remaining']);
            $response->headers->set('X-RateLimit-Reset', (string) $rateLimit['reset']);
        }
    }

    private function getLimitForRoute(string $route): ?array
    {
        // Exact match first
        if (isset(self::LIMITS[$route])) {
            return self::LIMITS[$route];
        }

        // Pattern matching
        foreach (self::LIMITS as $pattern => $limit) {
            if (str_starts_with($route, str_replace(['_'], '', $pattern))) {
                return $limit;
            }
        }

        // Default for API routes
        if (str_starts_with($route, 'api_')) {
            return self::LIMITS['api_rooms'];
        }

        return null;
    }
}