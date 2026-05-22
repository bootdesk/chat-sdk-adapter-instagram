<?php

namespace BootDesk\ChatSDK\Instagram;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class InstagramWebhookVerifier
{
    public function __construct(
        private readonly string $appSecret,
        private readonly string $verifyToken,
        private readonly ?Psr17Factory $psrFactory = null,
    ) {}

    public function verifySignature(string $body, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $parts = explode('=', $signature, 2);
        if ($parts[0] !== 'sha256' || ! isset($parts[1])) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $this->appSecret);

        return hash_equals($expected, $parts[1]);
    }

    public function handleVerificationChallenge(ServerRequestInterface $request): ?ResponseInterface
    {
        $params = $request->getQueryParams();
        $mode = $params['hub_mode'] ?? '';
        $token = $params['hub_verify_token'] ?? '';
        $challenge = $params['hub_challenge'] ?? '';

        if ($mode === 'subscribe' && $token === $this->verifyToken) {
            $factory = $this->psrFactory ?? new Psr17Factory;

            return $factory->createResponse(200)
                ->withBody($factory->createStream($challenge));
        }

        return null;
    }
}
