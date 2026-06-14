<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Instagram;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesActions;
use BootDesk\ChatSDK\Core\Contracts\HandlesBatchedWebhooks;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlashCommands;
use BootDesk\ChatSDK\Core\Contracts\HandlesStatuses;
use BootDesk\ChatSDK\Core\Contracts\HasAuthorInfo;
use BootDesk\ChatSDK\Core\Contracts\RequiresAsyncResponse;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\EmojiResolver;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use BootDesk\ChatSDK\Core\WebhookEvent;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class InstagramAdapter implements Adapter, HandlesActions, HandlesBatchedWebhooks, HandlesReactions, HandlesSlashCommands, HandlesStatuses, HasAuthorInfo, RequiresAsyncResponse
{
    protected ?string $botUserId = null;

    protected InstagramFormatConverter $formatConverter;

    protected InstagramWebhookVerifier $webhookVerifier;

    protected FileUploadConverter $fileUploadConverter;

    protected readonly string $authMode;

    protected readonly string $apiUrl;

    protected EmojiResolver $emojiResolver;

    public function __construct(
        protected readonly ClientInterface $httpClient,
        string $verifyToken,
        protected readonly string $appSecret,
        // Old path (Facebook Page → graph.facebook.com)
        protected readonly ?string $pageAccessToken = null,
        // New path (Instagram Login → graph.instagram.com)
        protected readonly ?string $igAccessToken = null,
        protected readonly ?string $igUserId = null,
        protected readonly string $apiVersion = 'v25.0',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
        ?EmojiResolver $emojiResolver = null,
    ) {
        $this->formatConverter = new InstagramFormatConverter;
        $this->webhookVerifier = new InstagramWebhookVerifier($appSecret, $verifyToken, $psrFactory);
        $this->emojiResolver = $emojiResolver ?? EmojiResolver::default();
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;

        $this->authMode = $pageAccessToken !== null ? 'page' : 'ig';
        $this->apiUrl = $this->authMode === 'page'
            ? 'https://graph.facebook.com'
            : 'https://graph.instagram.com';

        if ($this->authMode === 'page' && $this->pageAccessToken === null) {
            throw new \InvalidArgumentException('Either pageAccessToken or igAccessToken + igUserId must be provided');
        }
        if ($this->authMode === 'ig' && ($this->igAccessToken === null || $this->igUserId === null)) {
            throw new \InvalidArgumentException('igAccessToken and igUserId are required when not using a Page access token');
        }
    }

    public static function createWithPageToken(
        ClientInterface $httpClient,
        string $pageAccessToken,
        string $appSecret,
        string $verifyToken,
        string $apiVersion = 'v25.0',
        ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
    ): self {
        return new self(
            httpClient: $httpClient,
            verifyToken: $verifyToken,
            appSecret: $appSecret,
            pageAccessToken: $pageAccessToken,
            apiVersion: $apiVersion,
            psrFactory: $psrFactory,
            fileUploadConverter: $fileUploadConverter,
        );
    }

    public static function createWithIgToken(
        ClientInterface $httpClient,
        string $igAccessToken,
        string $igUserId,
        string $appSecret,
        string $verifyToken,
        string $apiVersion = 'v25.0',
        ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
    ): self {
        return new self(
            httpClient: $httpClient,
            verifyToken: $verifyToken,
            appSecret: $appSecret,
            igAccessToken: $igAccessToken,
            igUserId: $igUserId,
            apiVersion: $apiVersion,
            psrFactory: $psrFactory,
            fileUploadConverter: $fileUploadConverter,
        );
    }

    public function getName(): string
    {
        return 'instagram';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        // GET — verification challenge
        if ($request->getMethod() === 'GET') {
            $challenge = $this->webhookVerifier->handleVerificationChallenge($request);

            return $challenge ?? $this->jsonError(403, 'Verification failed');
        }

        // POST — verify HMAC signature
        $body = (string) $request->getBody();
        $signature = $request->getHeaderLine('x-hub-signature-256');

        if (! $this->webhookVerifier->verifySignature($body, $signature)) {
            return $this->jsonError(403, 'Invalid signature');
        }

        return null;
    }

    public function parseReaction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'instagram') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $reaction = $event['reaction'] ?? null;
                if ($reaction === null) {
                    continue;
                }

                $emoji = $reaction['emoji'] ?? $reaction['reaction'] ?? '';
                $action = $reaction['action'] ?? '';
                $senderId = $event['sender']['id'] ?? '';

                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                return [
                    'author' => new Author(id: $senderId),
                    'emoji' => $this->emojiResolver->fromGChat($emoji),
                    'rawEmoji' => $emoji,
                    'added' => $action === 'react',
                    'threadId' => $threadId,
                    'messageId' => $reaction['mid'] ?? (string) ($event['timestamp'] ?? ''),
                    'userId' => $senderId,
                    'raw' => $payload,
                    'originId' => $originId,
                ];
            }
        }

        return null;
    }

    public function parseAction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'instagram') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $postback = $event['postback'] ?? null;
                if ($postback === null) {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                $decoded = InstagramCards::decodeCallbackData($postback['payload'] ?? null);

                return [
                    'author' => new Author(id: $senderId),
                    'actionId' => $decoded['actionId'],
                    'value' => $decoded['value'],
                    'threadId' => $threadId,
                    'messageId' => $postback['mid'] ?? (string) ($event['timestamp'] ?? ''),
                    'userId' => $senderId,
                    'isBot' => false,
                    'isMe' => false,
                    'triggerId' => null,
                    'raw' => $payload,
                    'callbackQueryId' => null,
                    'originId' => $originId,
                ];
            }
        }

        return null;
    }

    public function acknowledgeAction(?string $callbackQueryId): ?ResponseInterface
    {
        return null;
    }

    public function parseStatus(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'instagram') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                if (isset($event['delivery'])) {
                    return [
                        'type' => 'delivered',
                        'messageIds' => $event['delivery']['mids'] ?? [],
                        'threadId' => $threadId,
                        'userId' => $senderId,
                        'timestamp' => isset($event['delivery']['watermark']) ? (int) $event['delivery']['watermark'] : null,
                        'raw' => $payload,
                        'originId' => $originId,
                    ];
                }

                if (isset($event['read'])) {
                    return [
                        'type' => 'read',
                        'messageIds' => [],
                        'threadId' => $threadId,
                        'userId' => $senderId,
                        'timestamp' => isset($event['read']['watermark']) ? (int) $event['read']['watermark'] : null,
                        'raw' => $payload,
                        'originId' => $originId,
                    ];
                }
            }
        }

        return null;
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'instagram') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                $message = $event['message'] ?? null;
                if ($message === null || ($message['is_echo'] ?? false)) {
                    continue;
                }

                $rawText = $message['text'] ?? '';

                if ($rawText === '' || $rawText[0] !== '/') {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                $parts = explode(' ', $rawText, 2);
                $command = $parts[0];
                $text = $parts[1] ?? '';

                return [
                    'author' => new Author(id: $senderId),
                    'command' => $command,
                    'text' => $text,
                    'userId' => $senderId,
                    'isBot' => false,
                    'isMe' => false,
                    'channelId' => $threadId,
                    'triggerId' => null,
                    'raw' => $body,
                ];
            }
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if ($payload === null || ($payload['object'] ?? '') !== 'instagram') {
            throw new AdapterException('Invalid Instagram webhook payload');
        }

        // Walk entries to find the first user message
        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $message = $event['message'] ?? null;
                if ($message === null || ($message['is_echo'] ?? false)) {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? '';
                $text = $message['text'] ?? '';
                $mid = $message['mid'] ?? uniqid('msg_');

                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                return new Message(
                    id: $mid,
                    threadId: $threadId,
                    author: new Author(id: $senderId, isBot: false),
                    text: $text,
                    attachments: $this->extractAttachments($message),
                    isDM: true,
                    raw: $body,
                    originId: $originId,
                );
            }
        }

        throw new AdapterException('No user message found in Instagram webhook payload');
    }

    public function parseBatchedWebhook(ServerRequestInterface $request): array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'instagram') {
            return [];
        }

        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                // Reaction
                if (isset($event['reaction'])) {
                    $reaction = $event['reaction'];
                    $rawEmoji = $reaction['emoji'] ?? $reaction['reaction'] ?? '';
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_REACTION,
                        threadId: $threadId,
                        payload: [
                            'author' => new Author(id: $senderId),
                            'emoji' => $this->emojiResolver->fromGChat($rawEmoji),
                            'rawEmoji' => $rawEmoji,
                            'added' => ($reaction['action'] ?? '') === 'react',
                            'messageId' => $reaction['mid'] ?? (string) ($event['timestamp'] ?? ''),
                            'userId' => $senderId,
                            'raw' => $payload,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // Postback
                if (isset($event['postback'])) {
                    $postback = $event['postback'];
                    $decoded = InstagramCards::decodeCallbackData($postback['payload'] ?? null);
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_ACTION,
                        threadId: $threadId,
                        payload: [
                            'author' => new Author(id: $senderId),
                            'actionId' => $decoded['actionId'],
                            'value' => $decoded['value'],
                            'messageId' => $postback['mid'] ?? (string) ($event['timestamp'] ?? ''),
                            'userId' => $senderId,
                            'isBot' => false,
                            'isMe' => false,
                            'triggerId' => null,
                            'raw' => $payload,
                            'callbackQueryId' => null,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // Delivery
                if (isset($event['delivery'])) {
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_STATUS,
                        threadId: $threadId,
                        payload: [
                            'type' => 'delivered',
                            'messageIds' => $event['delivery']['mids'] ?? [],
                            'userId' => $senderId,
                            'timestamp' => isset($event['delivery']['watermark']) ? (int) $event['delivery']['watermark'] : null,
                            'raw' => $payload,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // Read
                if (isset($event['read'])) {
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_STATUS,
                        threadId: $threadId,
                        payload: [
                            'type' => 'read',
                            'messageIds' => [],
                            'userId' => $senderId,
                            'timestamp' => isset($event['read']['watermark']) ? (int) $event['read']['watermark'] : null,
                            'raw' => $payload,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // User message (skip echoes)
                $message = $event['message'] ?? null;
                if ($message !== null && ! ($message['is_echo'] ?? false)) {
                    $text = $message['text'] ?? '';
                    $mid = $message['mid'] ?? uniqid('msg_');

                    // Check if this is a slash command
                    if ($text !== '' && $text[0] === '/') {
                        $parts = explode(' ', $text, 2);
                        $command = $parts[0];
                        $cmdText = $parts[1] ?? '';

                        $events[] = new WebhookEvent(
                            type: WebhookEvent::TYPE_SLASH_COMMAND,
                            threadId: $threadId,
                            payload: [
                                'author' => new Author(id: $senderId),
                                'command' => $command,
                                'text' => $cmdText,
                                'userId' => $senderId,
                                'isBot' => false,
                                'isMe' => false,
                                'channelId' => $threadId,
                                'triggerId' => null,
                                'raw' => $payload,
                            ],
                            originId: $originId,
                        );
                    } else {
                        $events[] = new WebhookEvent(
                            type: WebhookEvent::TYPE_MESSAGE,
                            threadId: $threadId,
                            payload: new Message(
                                id: $mid,
                                threadId: $threadId,
                                author: new Author(id: $senderId, isBot: false),
                                text: $text,
                                attachments: $this->extractAttachments($message),
                                isDM: true,
                                raw: $body,
                                originId: $originId,
                            ),
                            originId: $originId,
                        );
                    }
                }
            }
        }

        return $events;
    }

    public function encodeThreadId(mixed $platformData): string
    {
        return 'instagram:'.($platformData['recipientId'] ?? '');
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 2);

        if ($parts[0] !== 'instagram' || ! isset($parts[1]) || $parts[1] === '') {
            throw new AdapterException("Invalid Instagram thread ID: {$threadId}");
        }

        return ['recipientId' => $parts[1]];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return $threadId;
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $recipientId = $decoded['recipientId'];

        // Convert files to attachments via the registered converter
        if ($message->files !== []) {
            $converted = [];
            foreach ($message->files as $file) {
                $converted[] = $this->fileUploadConverter->upload($file, $this);
            }
            $message = new PostableMessage(
                content: $message->content,
                replyToMessageId: $message->replyToMessageId,
                attachments: array_merge($message->attachments, $converted),
            );
        }

        $basePayload = [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
        ];

        if ($message->replyToMessageId !== null) {
            $basePayload['reply_to'] = ['mid' => $message->replyToMessageId];
        }

        // Quick replies from metadata — Instagram supports up to 13
        $quickReplies = $message->metadata['quick_replies'] ?? null;

        // Attachments take priority
        if ($message->attachments !== []) {
            $text = $this->formatConverter->renderPostable($message);

            // Sticker (like_heart)
            if ($message->attachments[0]->type === 'sticker' || $message->attachments[0]->type === 'like_heart') {
                $response = $this->graphApiCall($this->messagesEndpoint(), [
                    ...$basePayload,
                    'message' => [
                        'attachment' => ['type' => 'like_heart'],
                    ],
                ]);

                $additionalMessages = [];
                if ($text !== '') {
                    $textResponse = $this->graphApiCall($this->messagesEndpoint(), [
                        ...$basePayload,
                        'message' => ['text' => $this->truncate($text)],
                    ]);
                    $additionalMessages[] = new SentMessage(
                        id: $textResponse['message_id'] ?? '',
                        threadId: $threadId,
                        timestamp: (string) time(),
                        raw: $textResponse,
                    );
                }

                return new SentMessage(
                    id: $response['message_id'] ?? '',
                    threadId: $threadId,
                    timestamp: (string) time(),
                    additionalMessages: $additionalMessages,
                    raw: $response,
                );
            }

            // MEDIA_SHARE — send uploaded media or published post
            $meta = $message->attachments[0]->fetchMetadata ?? [];
            if (isset($meta['attachment_id']) || $message->attachments[0]->type === 'media_share') {
                $id = $meta['attachment_id'] ?? $meta['id'] ?? '';
                $response = $this->graphApiCall($this->messagesEndpoint(), [
                    ...$basePayload,
                    'message' => [
                        'attachment' => [
                            'type' => 'MEDIA_SHARE',
                            'payload' => ['attachment_id' => $id],
                        ],
                    ],
                ]);

                $additionalMessages = [];
                if ($text !== '') {
                    $textResponse = $this->graphApiCall($this->messagesEndpoint(), [
                        ...$basePayload,
                        'message' => ['text' => $this->truncate($text)],
                    ]);
                    $additionalMessages[] = new SentMessage(
                        id: $textResponse['message_id'] ?? '',
                        threadId: $threadId,
                        timestamp: (string) time(),
                        raw: $textResponse,
                    );
                }

                return new SentMessage(
                    id: $response['message_id'] ?? '',
                    threadId: $threadId,
                    timestamp: (string) time(),
                    additionalMessages: $additionalMessages,
                    raw: $response,
                );
            }

            // Publish post share — MEDIA_SHARE with post ID
            if (isset($meta['post_id'])) {
                $response = $this->graphApiCall($this->messagesEndpoint(), [
                    ...$basePayload,
                    'message' => [
                        'attachment' => [
                            'type' => 'MEDIA_SHARE',
                            'payload' => ['id' => $meta['post_id']],
                        ],
                    ],
                ]);

                $additionalMessages = [];
                if ($text !== '') {
                    $textResponse = $this->graphApiCall($this->messagesEndpoint(), [
                        ...$basePayload,
                        'message' => ['text' => $this->truncate($text)],
                    ]);
                    $additionalMessages[] = new SentMessage(
                        id: $textResponse['message_id'] ?? '',
                        threadId: $threadId,
                        timestamp: (string) time(),
                        raw: $textResponse,
                    );
                }

                return new SentMessage(
                    id: $response['message_id'] ?? '',
                    threadId: $threadId,
                    timestamp: (string) time(),
                    additionalMessages: $additionalMessages,
                    raw: $response,
                );
            }

            // Multiple images — send up to 10 in a single request (Beta)
            $allImages = array_values(array_filter(
                $message->attachments,
                fn (Attachment $a): bool => $a->type === 'image',
            ));
            if (count($allImages) === count($message->attachments) && count($allImages) > 1) {
                $imageAttachments = [];
                foreach (array_slice($allImages, 0, 10) as $img) {
                    $imageAttachments[] = [
                        'type' => 'image',
                        'payload' => ['url' => $img->url],
                    ];
                }

                $response = $this->graphApiCall($this->messagesEndpoint(), [
                    ...$basePayload,
                    'message' => [
                        'attachments' => $imageAttachments,
                    ],
                ]);

                $additionalMessages = [];
                if ($text !== '') {
                    $textResponse = $this->graphApiCall($this->messagesEndpoint(), [
                        ...$basePayload,
                        'message' => ['text' => $this->truncate($text)],
                    ]);
                    $additionalMessages[] = new SentMessage(
                        id: $textResponse['message_id'] ?? '',
                        threadId: $threadId,
                        timestamp: (string) time(),
                        raw: $textResponse,
                    );
                }

                return new SentMessage(
                    id: $response['message_id'] ?? '',
                    threadId: $threadId,
                    timestamp: (string) time(),
                    additionalMessages: $additionalMessages,
                    raw: $response,
                );
            }

            // Single attachment
            $att = $message->attachments[0];

            $attachmentData = match ($att->type) {
                'image' => ['type' => 'image', 'payload' => ['url' => $att->url]],
                'video' => ['type' => 'video', 'payload' => ['url' => $att->url]],
                'audio' => ['type' => 'audio', 'payload' => ['url' => $att->url]],
                default => ['type' => 'file', 'payload' => ['url' => $att->url]],
            };

            $response = $this->graphApiCall($this->messagesEndpoint(), [
                ...$basePayload,
                'message' => [
                    'attachment' => $attachmentData,
                ],
            ]);

            $additionalMessages = [];
            if ($text !== '') {
                $textResponse = $this->graphApiCall($this->messagesEndpoint(), [
                    ...$basePayload,
                    'message' => ['text' => $this->truncate($text)],
                ]);
                $additionalMessages[] = new SentMessage(
                    id: $textResponse['message_id'] ?? '',
                    threadId: $threadId,
                    timestamp: (string) time(),
                    raw: $textResponse,
                );
            }

            return new SentMessage(
                id: $response['message_id'] ?? '',
                threadId: $threadId,
                timestamp: (string) time(),
                additionalMessages: $additionalMessages,
                raw: $response,
            );
        }

        if ($message->isTemplate()) {
            $template = $message->content;

            if (! $template instanceof InstagramTemplate) {
                throw new AdapterException('Unsupported template type for Instagram adapter');
            }

            $result = $template->toInstagram();

            $response = $this->graphApiCall($this->messagesEndpoint(), [
                ...$basePayload,
                'message' => $result['attachment'],
            ]);
        } elseif ($message->isCard()) {
            $cardResult = InstagramCards::toInstagramPayload($message->content);

            if ($cardResult['type'] === 'template') {
                $response = $this->graphApiCall($this->messagesEndpoint(), [
                    ...$basePayload,
                    'message' => $cardResult['attachment'],
                ]);
            } else {
                $msgPayload = ['text' => $this->truncate($cardResult['text'])];
                if ($quickReplies !== null) {
                    $msgPayload['quick_replies'] = $quickReplies;
                }
                $response = $this->graphApiCall($this->messagesEndpoint(), [
                    ...$basePayload,
                    'message' => $msgPayload,
                ]);
            }
        } else {
            $text = $this->formatConverter->renderPostable($message);
            $msgPayload = ['text' => $this->truncate($text)];
            if ($quickReplies !== null) {
                $msgPayload['quick_replies'] = $quickReplies;
            }
            $response = $this->graphApiCall($this->messagesEndpoint(), [
                ...$basePayload,
                'message' => $msgPayload,
            ]);
        }

        return new SentMessage(
            id: $response['message_id'] ?? '',
            threadId: $threadId,
            timestamp: (string) time(),
            raw: $response,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        throw new AdapterException('Instagram does not support editing messages');
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        throw new AdapterException('Instagram does not support deleting messages');
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->graphApiCall($this->messagesEndpoint(), [
            'recipient' => ['id' => $decoded['recipientId']],
            'sender_action' => 'react',
            'payload' => [
                'message_id' => $messageId,
                'reaction' => $this->emojiResolver->toGChat($emoji),
            ],
        ]);
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->graphApiCall($this->messagesEndpoint(), [
            'recipient' => ['id' => $decoded['recipientId']],
            'sender_action' => 'unreact',
            'payload' => [
                'message_id' => $messageId,
            ],
        ]);
    }

    public function startTyping(string $threadId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->graphApiCall($this->messagesEndpoint(), [
            'recipient' => ['id' => $decoded['recipientId']],
            'sender_action' => 'typing_on',
        ]);
    }

    public function markSeen(string $threadId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->graphApiCall($this->messagesEndpoint(), [
            'recipient' => ['id' => $decoded['recipientId']],
            'sender_action' => 'mark_seen',
        ]);
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        return new FetchResult(messages: []);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        return new ThreadInfo(
            id: $threadId,
            channelId: $threadId,
            messageCount: 0,
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        $decoded = $this->decodeThreadId($channelId);

        $fields = $this->authMode === 'ig' ? 'name,username,profile_pic' : 'username,profile_pic';
        $response = $this->graphApiCall($decoded['recipientId'], [], 'GET', ['fields' => $fields]);

        $name = $this->authMode === 'ig'
            ? ($response['name'] ?? $response['username'] ?? $channelId)
            : ($response['username'] ?? $channelId);

        return new ChannelInfo(
            id: $channelId,
            name: $name,
            isPrivate: true,
        );
    }

    public function getUser(string $userId): ?UserInfo
    {
        $fields = $this->authMode === 'ig' ? 'name,username,profile_pic' : 'username,profile_pic';
        $response = $this->graphApiCall($userId, [], 'GET', ['fields' => $fields]);

        $name = $this->authMode === 'ig'
            ? ($response['name'] ?? $response['username'] ?? $userId)
            : ($response['username'] ?? $userId);

        return new UserInfo(
            id: $userId,
            name: $name,
        );
    }

    public function getAuthorInfo(Author $author): Author
    {
        $fields = $this->authMode === 'ig' ? 'name,username,profile_pic' : 'username,profile_pic';
        $response = $this->graphApiCall($author->id, [], 'GET', ['fields' => $fields]);

        $name = $this->authMode === 'ig'
            ? ($response['name'] ?? $response['username'] ?? null)
            : ($response['username'] ?? null);

        $profilePicture = $response['profile_pic'] ?? null;

        if ($name === null && $profilePicture === null) {
            return $author;
        }

        return new Author(
            id: $author->id,
            name: $name ?? $author->name,
            email: $author->email,
            isMe: $author->isMe,
            isBot: $author->isBot,
            profilePicture: $profilePicture ?? $author->profilePicture,
        );
    }

    public function openDM(string $userId): ?string
    {
        return $this->encodeThreadId(['recipientId' => $userId]);
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        if ($this->authMode === 'ig') {
            $this->botUserId = $this->igUserId;

            return;
        }

        try {
            $me = $this->graphApiCall('me', [], 'GET');
            $this->botUserId = $me['id'] ?? null;
        } catch (AdapterException) {
            // Bot identity unavailable — continue without it
        }
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function truncate(string $text, int $limit = 1000): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit - 3).'...';
    }

    /** @return Attachment[] */
    protected function extractAttachments(array $message): array
    {
        $attachments = [];

        foreach ($message['attachments'] ?? [] as $att) {
            $rawType = $att['type'] ?? '';

            $type = match ($rawType) {
                'image', 'video', 'audio', 'file', 'fallback' => $rawType,
                'media', 'story_mention', 'story', 'ig_story' => $rawType,
                'reel', 'ig_reel', 'post', 'ig_post', 'appointment_booking', 'template' => $rawType,
                default => 'file',
            };

            $payload = $att['payload'] ?? [];

            $metadata = match ($rawType) {
                'fallback', 'reel', 'ig_reel', 'post', 'ig_post' => array_filter([
                    'title' => $payload['title'] ?? null,
                    'reel_video_id' => $payload['reel_video_id'] ?? null,
                    'id' => $payload['id'] ?? null,
                ]),
                'appointment_booking' => array_filter([
                    'booking_id' => $payload['booking_id'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'start_time' => $payload['start_time'] ?? null,
                    'end_time' => $payload['end_time'] ?? null,
                    'timezone' => $payload['timezone'] ?? null,
                ]),
                'template' => array_filter([
                    'product' => $payload['product'] ?? null,
                ]),
                'image' => array_filter([
                    'sticker_id' => $payload['sticker_id'] ?? null,
                ]),
                default => [],
            };

            $attachments[] = new Attachment(
                type: $type,
                url: $payload['url'] ?? null,
                mimeType: $payload['mime_type'] ?? null,
                fetchMetadata: $metadata !== [] ? $metadata : null,
            );
        }

        return $attachments;
    }

    protected function messagesEndpoint(): string
    {
        return $this->authMode === 'page' ? 'me/messages' : "{$this->igUserId}/messages";
    }

    protected function graphApiCall(string $endpoint, array $params, string $method = 'POST', array $queryParams = []): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        if ($this->authMode === 'ig') {
            $url = "{$this->apiUrl}/{$this->apiVersion}/{$endpoint}";
            if ($queryParams !== []) {
                $url .= '?'.http_build_query($queryParams);
            }

            $request = $factory->createRequest($method, $url)
                ->withHeader('Authorization', "Bearer {$this->igAccessToken}");

            if ($method !== 'GET') {
                $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream($body));
            }
        } else {
            $proof = hash_hmac('sha256', $this->pageAccessToken, $this->appSecret);
            $url = "{$this->apiUrl}/{$this->apiVersion}/{$endpoint}?access_token={$this->pageAccessToken}&appsecret_proof={$proof}";

            if ($queryParams !== []) {
                $url .= '&'.http_build_query($queryParams);
            }

            if ($method === 'GET') {
                $request = $factory->createRequest('GET', $url);
            } else {
                $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
                $request = $factory->createRequest($method, $url)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream($body));
            }
        }

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();

        $data = json_decode($responseBody, true);

        if ($data === null) {
            return [];
        }

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from Instagram API: {$endpoint}");
        }

        if (isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? 'unknown_error';
            $errorCode = $data['error']['code'] ?? 0;
            $errorType = $data['error']['type'] ?? '';

            if (in_array($errorCode, [10, 190, 200], true) || $errorType === 'OAuthException') {
                throw new AuthenticationException("Instagram API authentication error ({$endpoint}): {$errorMsg}");
            }

            throw new AdapterException("Instagram API error ({$endpoint}): {$errorMsg}");
        }

        return $data;
    }

    protected function jsonError(int $status, string $message): ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        return $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(['error' => $message])));
    }
}
