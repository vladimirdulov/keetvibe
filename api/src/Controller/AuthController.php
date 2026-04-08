<?php

namespace App\Controller;

use App\DTO\LoginRequest;
use App\DTO\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\JWTService;
use App\Service\RedisService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class AuthController extends AbstractController
{
    private const TOKEN_BLACKLIST_PREFIX = 'jwt_blacklist:';
    
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly JWTService $jwtService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly ?RedisService $redisService,
    ) {}

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $dto = new RegisterRequest();
        $dto->email = $data['email'] ?? '';
        $dto->password = $data['password'] ?? '';
        $dto->name = $data['name'] ?? '';

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json(['errors' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        if ($this->userRepository->findByEmail($dto->email)) {
            return $this->json(['error' => 'Email already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($dto->email);
        $user->setName($dto->name);
        $user->setPassword($this->passwordHasher->hashPassword($user, $dto->password));
        $user->setRoles([User::ROLE_VIEWER]);

        $this->userRepository->save($user, true);

        $token = $this->jwtService->generateToken($user);

        return $this->json([
            'message' => 'User registered successfully',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $dto = new LoginRequest();
        $dto->email = $data['email'] ?? '';
        $dto->password = $data['password'] ?? '';

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json(['errors' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        /** @var User|null $user */
        $user = $this->userRepository->findByEmail($dto->email);
        
        if (!$user || !$this->passwordHasher->isPasswordValid($user, $dto->password)) {
            return $this->json(['error' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        $token = $this->jwtService->generateToken($user);

        return $this->json([
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
            ]
        ]);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'roles' => $user->getRoles(),
        ]);
    }

    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        // Get the current JWT token from the request
        $token = $this->jwtManager->decode(
            $this->get('security.token_storage')->getToken()
        );

        if ($token && isset($token['exp'])) {
            $expiry = $token['exp'];
            $ttl = $expiry - time();
            
            // If token is still valid, blacklist it
            if ($ttl > 0 && $this->redisService) {
                $this->redisService->set(
                    self::TOKEN_BLACKLIST_PREFIX . $token['jti'],
                    '1',
                    $ttl
                );
            }
        }

        // Invalidate the security token
        $this->get('security.token_storage')->setToken(null);

        return $this->json(['message' => 'Logged out successfully']);
    }

    #[Route('/refresh', name: 'api_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $oldToken = $data['token'] ?? null;

        if (!$oldToken) {
            return $this->json(['error' => 'Token required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Decode the old token
            $decoded = $this->jwtManager->parse($oldToken);
            
            // Check if token is blacklisted
            if (isset($decoded['jti']) && $this->redisService) {
                $blacklisted = $this->redisService->get(
                    self::TOKEN_BLACKLIST_PREFIX . $decoded['jti']
                );
                if ($blacklisted) {
                    return $this->json(['error' => 'Token has been revoked'], Response::HTTP_UNAUTHORIZED);
                }
            }

            // Get user from token and generate new token
            $user = $this->userRepository->find($decoded['id']);
            if (!$user) {
                return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
            }

            $newToken = $this->jwtService->generateToken($user);

            return $this->json([
                'token' => $newToken,
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'name' => $user->getName(),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Invalid token'], Response::HTTP_UNAUTHORIZED);
        }
    }
}