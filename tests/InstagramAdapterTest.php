<?php

namespace BootDesk\ChatSDK\Instagram\Tests;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\Exceptions\UnsupportedOperationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\WebhookEvent;
use BootDesk\ChatSDK\Instagram\InstagramAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class InstagramAdapterTest extends TestCase
{
    private InstagramAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $uri = (string) $request->getUri();
                $method = $request->getMethod();

                // POST me/messages → send message
                if ($method === 'POST' && str_contains($uri, 'me/messages')) {
                    $body = (string) $request->getBody();
                    $data = json_decode($body, true);
                    $hasTyping = isset($data['sender_action']);

                    if ($hasTyping) {
                        return $factory->createResponse(200)->withBody(
                            $factory->createStream(json_encode(['success' => true]))
                        );
                    }

                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'message_id' => 'mid.123456',
                            'recipient_id' => 'U999',
                        ]))
                    );
                }

                // GET me → bot identity
                if ($method === 'GET' && preg_match('#/me\?#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['id' => 'PAGE123', 'name' => 'MyBot']))
                    );
                }

                // GET {userId} → user profile
                if ($method === 'GET' && preg_match('#/\d+\?#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => '123456',
                            'username' => 'johndoe',
                        ]))
                    );
                }

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['id' => 'fallback']))
                );
            }
        };

        $this->adapter = new InstagramAdapter(
            pageAccessToken: 'test-page-token',
            appSecret: 'test_app_secret',
            verifyToken: 'test_verify_token',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    // --- Construction ---

    public function test_get_name(): void
    {
        $this->assertSame('instagram', $this->adapter->getName());
    }

    public function test_initialize_sets_bot_user_id(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);
        $this->assertSame('PAGE123', $this->adapter->getBotUserId());
    }

    // --- Thread IDs ---

    public function test_thread_id_encode(): void
    {
        $id = $this->adapter->encodeThreadId(['recipientId' => '123456']);
        $this->assertSame('instagram:123456', $id);
    }

    public function test_thread_id_decode(): void
    {
        $decoded = $this->adapter->decodeThreadId('instagram:123456');
        $this->assertSame('123456', $decoded['recipientId']);
    }

    public function test_thread_id_decode_invalid(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('not-instagram');
    }

    public function test_thread_id_decode_empty_recipient(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('instagram:');
    }

    public function test_channel_id_is_thread_id(): void
    {
        $this->assertSame('instagram:123', $this->adapter->channelIdFromThreadId('instagram:123'));
    }

    // --- Webhook verification ---

    public function test_get_challenge_verification(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhook?hub_mode=subscribe&hub_verify_token=test_verify_token&hub_challenge=challenge_abc');

        $response = $this->adapter->verifyWebhook($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('challenge_abc', (string) $response->getBody());
    }

    public function test_get_challenge_wrong_token(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc');

        $response = $this->adapter->verifyWebhook($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_post_valid_signature(): void
    {
        $body = json_encode(['object' => 'instagram', 'entry' => []]);
        $hash = hash_hmac('sha256', $body, 'test_app_secret');

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-hub-signature-256', "sha256={$hash}")
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNull($response);
    }

    public function test_post_invalid_signature(): void
    {
        $body = '{"object":"instagram"}';
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-hub-signature-256', 'sha256=badhash')
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Parse webhook ---

    public function test_parse_webhook_message(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $body = json_encode([
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => 'PAGE1',
                    'messaging' => [
                        [
                            'sender' => ['id' => '123456'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1234567890,
                            'message' => [
                                'mid' => 'mid.abc',
                                'text' => 'Hello bot',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('mid.abc', $message->id);
        $this->assertSame('instagram:123456', $message->threadId);
        $this->assertSame('123456', $message->author->id);
        $this->assertSame('Hello bot', $message->text);
        $this->assertTrue($message->isDM);
        $this->assertSame('PAGE1', $message->originId);
    }

    public function test_parse_webhook_skips_echo(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [
                [
                    'messaging' => [
                        [
                            'sender' => ['id' => 'PAGE1'],
                            'recipient' => ['id' => '123456'],
                            'message' => ['mid' => 'm1', 'text' => 'echo', 'is_echo' => true],
                        ],
                        [
                            'sender' => ['id' => '123456'],
                            'recipient' => ['id' => 'PAGE1'],
                            'message' => ['mid' => 'm2', 'text' => 'real msg'],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('m2', $message->id);
        $this->assertSame('real msg', $message->text);
    }

    public function test_parse_webhook_invalid_payload(): void
    {
        $this->expectException(AdapterException::class);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{"object":"not_instagram"}'));

        $this->adapter->parseWebhook($request);
    }

    public function test_parse_webhook_no_user_message(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        $body = json_encode([
            'object' => 'instagram',
            'entry' => [
                ['messaging' => [
                    ['sender' => ['id' => 'P1'], 'message' => ['mid' => 'm1', 'is_echo' => true]],
                ]],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $this->adapter->parseWebhook($request);
    }

    // --- Message operations ---

    public function test_post_message(): void
    {
        $sent = $this->adapter->postMessage('instagram:123456', PostableMessage::text('Hello'));

        $this->assertSame('mid.123456', $sent->id);
        $this->assertSame('instagram:123456', $sent->threadId);
    }

    public function test_post_message_with_card_template(): void
    {
        $card = Card::make()
            ->header('Deploy Ready')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $sent = $this->adapter->postMessage('instagram:123456', PostableMessage::card($card));
        $this->assertSame('mid.123456', $sent->id);
    }

    public function test_post_message_with_card_text_fallback(): void
    {
        $card = Card::make()->section(fn ($s) => $s->text('Just text'));
        $sent = $this->adapter->postMessage('instagram:123456', PostableMessage::card($card));
        $this->assertSame('mid.123456', $sent->id);
    }

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream('instagram:123456', ['Hello ', 'World']);
        $this->assertNotNull($sent);
        $this->assertSame('mid.123456', $sent->id);
    }

    public function test_stream_empty_returns_null(): void
    {
        $this->assertNull($this->adapter->stream('instagram:123456', []));
    }

    // --- Unsupported operations ---

    public function test_edit_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->editMessage('instagram:123', 'm1', PostableMessage::text('x'));
    }

    public function test_delete_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->deleteMessage('instagram:123', 'm1');
    }

    public function test_add_reaction(): void
    {
        $this->adapter->addReaction('instagram:123456', 'm1', 'love');
        $this->assertTrue(true);
    }

    public function test_remove_reaction(): void
    {
        $this->adapter->removeReaction('instagram:123456', 'm1', 'love');
        $this->assertTrue(true);
    }

    // --- Supported operations ---

    public function test_start_typing(): void
    {
        $this->adapter->startTyping('instagram:123456');
        $this->assertTrue(true);
    }

    public function test_fetch_messages_returns_empty(): void
    {
        $result = $this->adapter->fetchMessages('instagram:123');
        $this->assertCount(0, $result->messages);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('instagram:123');
        $this->assertSame('instagram:123', $info->id);
    }

    public function test_fetch_channel_info(): void
    {
        $info = $this->adapter->fetchChannelInfo('instagram:123456');
        $this->assertSame('instagram:123456', $info->id);
        $this->assertSame('johndoe', $info->name);
        $this->assertTrue($info->isPrivate);
    }

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('123456');
        $this->assertSame('123456', $user->id);
        $this->assertSame('johndoe', $user->name);
    }

    public function test_open_dm(): void
    {
        $threadId = $this->adapter->openDM('123456');
        $this->assertSame('instagram:123456', $threadId);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_create_response_returns_null(): void
    {
        $this->assertNull($this->adapter->createResponse());
    }

    public function test_post_message_truncates_long_text(): void
    {
        $factory = new Psr17Factory;
        $captured = [];

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['recipient_id' => '123', 'message_id' => 'mid.999']))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $longText = str_repeat('a', 3000);
        $adapter->postMessage('instagram:123:456', PostableMessage::text($longText));

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);
        $this->assertStringEndsWith('...', $body['message']['text']);
        $this->assertSame(1000, strlen($body['message']['text']));
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(200)->withBody(
                    $f->createStream(json_encode(['error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'Invalid access token']]))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'bad-token',
            appSecret: 'secret',
            verifyToken: 'verify',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('instagram:123:456', PostableMessage::text('test'));
    }

    public function test_parse_status_delivery(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => '12345'],
                    'recipient' => ['id' => '67890'],
                    'timestamp' => 1700000000,
                    'delivery' => ['mids' => ['mid.1', 'mid.2'], 'watermark' => 1700000000],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/instagram')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('delivered', $result['type']);
        $this->assertSame(['mid.1', 'mid.2'], $result['messageIds']);
        $this->assertSame('12345', $result['userId']);
    }

    public function test_parse_status_read(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => '12345'],
                    'recipient' => ['id' => '67890'],
                    'timestamp' => 1700000001,
                    'read' => ['watermark' => 1700000001],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/instagram')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('read', $result['type']);
        $this->assertSame('12345', $result['userId']);
        $this->assertSame(1700000001, $result['timestamp']);
    }

    public function test_parse_status_not_instagram_object(): void
    {
        $body = json_encode(['object' => 'other']);

        $request = $this->factory->createServerRequest('POST', '/webhooks/instagram')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseStatus($request));
    }

    public function test_parse_status_no_messaging(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [['messaging' => []]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/instagram')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseStatus($request));
    }

    // --- Instagram-specific features ---

    public function test_mark_seen(): void
    {
        $this->adapter->markSeen('instagram:123456');
        $this->assertTrue(true);
    }

    public function test_post_text_with_quick_replies(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.qr_001',
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $adapter->postMessage(
            'instagram:123456',
            new PostableMessage(
                content: 'Pick an option',
                metadata: [
                    'quick_replies' => [
                        ['content_type' => 'text', 'title' => 'Yes', 'payload' => 'YES'],
                        ['content_type' => 'text', 'title' => 'No', 'payload' => 'NO'],
                    ],
                ],
            ),
        );

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);

        $this->assertArrayHasKey('quick_replies', $body['message']);
        $this->assertCount(2, $body['message']['quick_replies']);
        $this->assertSame('Yes', $body['message']['quick_replies'][0]['title']);
        $this->assertSame('NO', $body['message']['quick_replies'][1]['payload']);
    }

    public function test_post_sticker_via_like_heart(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.sticker_001',
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $adapter->postMessage(
            'instagram:123456',
            new PostableMessage(
                content: '',
                attachments: [new Attachment(type: 'sticker')],
            ),
        );

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);

        $this->assertSame('like_heart', $body['message']['attachment']['type']);
    }

    public function test_post_media_share_via_attachment_id(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.media_001',
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $attachment = new Attachment(
            type: 'media_share',
            fetchMetadata: ['attachment_id' => 'ATTACHMENT_123'],
        );

        $adapter->postMessage(
            'instagram:123456',
            new PostableMessage(content: '', attachments: [$attachment]),
        );

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);

        $this->assertSame('MEDIA_SHARE', $body['message']['attachment']['type']);
        $this->assertSame('ATTACHMENT_123', $body['message']['attachment']['payload']['attachment_id']);
    }

    public function test_post_multiple_images(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.multi_001',
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $adapter->postMessage(
            'instagram:123456',
            new PostableMessage(
                content: '',
                attachments: [
                    new Attachment(type: 'image', url: 'https://example.com/1.jpg'),
                    new Attachment(type: 'image', url: 'https://example.com/2.jpg'),
                    new Attachment(type: 'image', url: 'https://example.com/3.jpg'),
                ],
            ),
        );

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);

        $this->assertArrayHasKey('attachments', $body['message']);
        $this->assertCount(3, $body['message']['attachments']);
        $this->assertSame('image', $body['message']['attachments'][0]['type']);
        $this->assertSame('https://example.com/2.jpg', $body['message']['attachments'][1]['payload']['url']);
    }

    public function test_post_single_image_uses_singular_attachment(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.single_img',
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $adapter->postMessage(
            'instagram:123456',
            new PostableMessage(
                content: '',
                attachments: [
                    new Attachment(type: 'image', url: 'https://example.com/1.jpg'),
                ],
            ),
        );

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);

        $this->assertArrayHasKey('attachment', $body['message']);
        $this->assertArrayNotHasKey('attachments', $body['message']);
        $this->assertSame('image', $body['message']['attachment']['type']);
    }

    public function test_post_with_attachment_formats_markdown_in_follow_up_text(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.'.count($this->captured),
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new InstagramAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $adapter->postMessage(
            'instagram:123456',
            new PostableMessage(
                content: '**bold** _italic_ ~strike~ `code`',
                attachments: [new Attachment(url: 'https://example.com/photo.jpg', type: 'image')],
            ),
        );

        $this->assertCount(2, $captured);
        // First request = attachment
        $body1 = json_decode((string) $captured[0]->getBody(), true);
        $this->assertArrayHasKey('attachment', $body1['message']);
        // Second request = follow-up text, should be formatted
        $body2 = json_decode((string) $captured[1]->getBody(), true);
        $this->assertSame('*bold* _italic_ ~strike~ `code`', $body2['message']['text']);
    }

    // --- Dual-path: Instagram Login (graph.instagram.com) ---

    public function test_create_with_ig_token(): void
    {
        $client = $this->createMock(ClientInterface::class);

        $adapter = InstagramAdapter::createWithIgToken(
            httpClient: $client,
            igAccessToken: 'ig_token_123',
            igUserId: '123456789',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
        );

        $this->assertSame('instagram', $adapter->getName());
    }

    public function test_ig_path_initializes_with_ig_user_id(): void
    {
        $client = $this->createMock(ClientInterface::class);

        $adapter = InstagramAdapter::createWithIgToken(
            httpClient: $client,
            igAccessToken: 'ig_token_123',
            igUserId: 'IG_ACCOUNT_001',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
        );

        $chat = $this->createMock(Chat::class);
        $adapter->initialize($chat);

        $this->assertSame('IG_ACCOUNT_001', $adapter->getBotUserId());
    }

    public function test_ig_path_sends_message_via_instagram_domain(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = [
                    'uri' => (string) $request->getUri(),
                    'headers' => $request->getHeaders(),
                    'body' => (string) $request->getBody(),
                ];

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.ig_001',
                        'recipient_id' => 'IGSID_001',
                    ]))
                );
            }
        };

        $adapter = InstagramAdapter::createWithIgToken(
            httpClient: $mockClient,
            igAccessToken: 'ig_token_123',
            igUserId: 'IG_ACCOUNT_001',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            psrFactory: $factory,
        );

        $adapter->postMessage('instagram:IGSID_001', PostableMessage::text('Hello from IG API'));

        $this->assertCount(1, $captured);
        $request = $captured[0];

        // Uses graph.instagram.com
        $this->assertStringContainsString('graph.instagram.com', $request['uri']);
        $this->assertStringContainsString('/IG_ACCOUNT_001/messages', $request['uri']);

        // Uses Authorization header instead of access_token query param
        $this->assertStringNotContainsString('access_token=', $request['uri']);
        $this->assertSame('Bearer ig_token_123', $request['headers']['Authorization'][0]);

        // Body is correct
        $body = json_decode($request['body'], true);
        $this->assertSame('Hello from IG API', $body['message']['text']);
    }

    public function test_ig_path_fetches_user_profile_with_correct_fields(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'id' => 'IGSID_001',
                        'name' => 'Peter Chang',
                        'username' => 'peter_chang_live',
                        'profile_pic' => 'https://example.com/pic.jpg',
                    ]))
                );
            }
        };

        $adapter = InstagramAdapter::createWithIgToken(
            httpClient: $mockClient,
            igAccessToken: 'ig_token_123',
            igUserId: 'IG_ACCOUNT_001',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            psrFactory: $factory,
        );

        $user = $adapter->getUser('IGSID_001');

        $this->assertSame('IGSID_001', $user->id);
        $this->assertSame('Peter Chang', $user->name);
    }

    public function test_create_with_page_token_factory(): void
    {
        $client = $this->createMock(ClientInterface::class);

        $adapter = InstagramAdapter::createWithPageToken(
            httpClient: $client,
            pageAccessToken: 'page_token_123',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
        );

        $this->assertSame('instagram', $adapter->getName());
    }

    // --- Fixture-based integration tests ---

    public function test_fixture_first_message(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['firstMessage'])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('m_FAKE_MSG_ID_001', $message->id);
        $this->assertSame('instagram:200000000000001', $message->threadId);
        $this->assertSame('What is BootDesk?', $message->text);
        $this->assertTrue($message->isDM);
    }

    public function test_fixture_delivery(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['deliveryConfirmation'])));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('delivered', $result['type']);
        $this->assertSame(['m_SENT_MSG_001'], $result['messageIds']);
        $this->assertIsInt($result['timestamp']);
    }

    public function test_fixture_seen(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['seenConfirmation'])));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('read', $result['type']);
    }

    public function test_fixture_reaction_added(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['reactionAdded'])));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('m_FAKE_MSG_ID_001', $result['messageId']);
        $this->assertSame('heart', $result['emoji']);
        $this->assertSame('❤', $result['rawEmoji']);
        $this->assertTrue($result['added']);
        $this->assertSame('instagram:200000000000001', $result['threadId']);
    }

    public function test_fixture_reaction_removed(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['reactionRemoved'])));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertFalse($result['added']);
    }

    public function test_fixture_postback_encoded(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['postbackEncoded'])));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('hello', $result['actionId']);
        $this->assertSame('instagram:200000000000001', $result['threadId']);
    }

    public function test_fixture_postback_legacy(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['postbackLegacy'])));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('GET_STARTED', $result['actionId']);
        $this->assertSame('GET_STARTED', $result['value']);
    }

    public function test_fixture_image_attachment(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['imageAttachment'])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertCount(1, $message->attachments);
        $this->assertSame('image', $message->attachments[0]->type);
        $this->assertSame('https://example.com/image.jpg', $message->attachments[0]->url);
    }

    public function test_fixture_echo_skipped(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['echoMessage'])));

        $this->expectException(UnsupportedOperationException::class);
        $this->adapter->parseWebhook($request);
    }

    public function test_fixture_deleted_message(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['deletedMessage'])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('m_DELETED_001', $message->id);
        $this->assertSame('', $message->text);
    }

    public function test_fixture_media_attachments(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['mediaAttachment'])));

        $message = $this->adapter->parseWebhook($request);
        $attachments = $message->attachments;

        $this->assertCount(6, $attachments);
        $this->assertSame('media', $attachments[0]->type);
        $this->assertSame('audio', $attachments[1]->type);
        $this->assertSame('file', $attachments[2]->type);
        $this->assertSame('ig_reel', $attachments[3]->type);
        $this->assertSame('story_mention', $attachments[4]->type);
        $this->assertSame('ig_post', $attachments[5]->type);
    }

    public function test_fixture_quick_reply(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['messageWithQuickReply'])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('m_QR_001', $message->id);
        $this->assertSame('Option 1', $message->text);
    }

    public function test_fixture_message_edit(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/instagram.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['messageEdit'])));

        // message_edit events should not be parsed as regular messages
        $this->expectException(UnsupportedOperationException::class);
        $this->adapter->parseWebhook($request);
    }

    public function test_parse_batched_multiple_messages(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => 'IG1',
                    'time' => 1000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'USER_A'],
                            'recipient' => ['id' => 'IG1'],
                            'timestamp' => 1000,
                            'message' => ['mid' => 'm1', 'text' => 'first'],
                        ],
                        [
                            'sender' => ['id' => 'USER_B'],
                            'recipient' => ['id' => 'IG1'],
                            'timestamp' => 1001,
                            'message' => ['mid' => 'm2', 'text' => 'second'],
                        ],
                    ],
                ],
                [
                    'id' => 'IG1',
                    'time' => 1002,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'USER_C'],
                            'recipient' => ['id' => 'IG1'],
                            'timestamp' => 1002,
                            'message' => ['mid' => 'm3', 'text' => 'third'],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $events = $this->adapter->parseBatchedWebhook($request);

        $this->assertCount(3, $events);
        $this->assertSame('message', $events[0]->type);
        $this->assertSame('instagram:USER_A', $events[0]->threadId);
        $this->assertSame('first', $events[0]->payload->text);
        $this->assertSame('IG1', $events[0]->originId);
        $this->assertSame('instagram:USER_B', $events[1]->threadId);
        $this->assertSame('second', $events[1]->payload->text);
        $this->assertSame('IG1', $events[1]->originId);
        $this->assertSame('instagram:USER_C', $events[2]->threadId);
        $this->assertSame('third', $events[2]->payload->text);
        $this->assertSame('IG1', $events[2]->originId);
    }

    public function test_parse_batched_mixed_event_types(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => 'IG1',
                    'time' => 1000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'USER_A'],
                            'recipient' => ['id' => 'IG1'],
                            'timestamp' => 1000,
                            'message' => ['mid' => 'm1', 'text' => 'hello'],
                        ],
                        [
                            'sender' => ['id' => 'USER_B'],
                            'recipient' => ['id' => 'IG1'],
                            'timestamp' => 1001,
                            'postback' => ['payload' => 'chat:{"a":"test","v":"1"}', 'mid' => 'pb1'],
                        ],
                        [
                            'sender' => ['id' => 'USER_C'],
                            'recipient' => ['id' => 'IG1'],
                            'timestamp' => 1002,
                            'reaction' => ['reaction' => '🎉', 'action' => 'react', 'mid' => 'r1'],
                        ],
                        [
                            'sender' => ['id' => 'USER_D'],
                            'recipient' => ['id' => 'IG1'],
                            'timestamp' => 1003,
                            'delivery' => ['mids' => ['d1'], 'watermark' => 1003],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $events = $this->adapter->parseBatchedWebhook($request);

        $this->assertCount(4, $events);
        $this->assertSame('message', $events[0]->type);
        $this->assertSame('hello', $events[0]->payload->text);
        $this->assertSame('IG1', $events[0]->originId);
        $this->assertSame('action', $events[1]->type);
        $this->assertSame('test', $events[1]->payload['actionId']);
        $this->assertSame('1', $events[1]->payload['value']);
        $this->assertSame('IG1', $events[1]->originId);
        $this->assertSame('reaction', $events[2]->type);
        $this->assertSame('party', $events[2]->payload['emoji']);
        $this->assertSame('🎉', $events[2]->payload['rawEmoji']);
        $this->assertTrue($events[2]->payload['added']);
        $this->assertSame('IG1', $events[2]->originId);
        $this->assertSame('status', $events[3]->type);
        $this->assertSame('delivered', $events[3]->payload['type']);
        $this->assertSame('IG1', $events[3]->originId);
    }

    public function test_parse_batched_skips_echo_messages(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => 'IG1',
                    'time' => 1000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'IG1'],
                            'recipient' => ['id' => 'USER_A'],
                            'message' => ['mid' => 'm1', 'text' => 'echo', 'is_echo' => true],
                        ],
                        [
                            'sender' => ['id' => 'USER_A'],
                            'recipient' => ['id' => 'IG1'],
                            'message' => ['mid' => 'm2', 'text' => 'real'],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $events = $this->adapter->parseBatchedWebhook($request);

        $this->assertCount(2, $events);
        $this->assertSame(WebhookEvent::TYPE_UNSUPPORTED, $events[0]->type);
        $this->assertSame('real', $events[1]->payload->text);
    }

    public function test_parse_batched_invalid_object(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{"object":"not_instagram"}'));

        $this->assertSame([], $this->adapter->parseBatchedWebhook($request));
    }

    public function test_parse_batched_empty_payload(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{}'));

        $this->assertSame([], $this->adapter->parseBatchedWebhook($request));
    }

    public function test_parse_batched_no_messaging(): void
    {
        $body = json_encode([
            'object' => 'instagram',
            'entry' => [['id' => 'IG1', 'time' => 1000]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $this->assertSame([], $this->adapter->parseBatchedWebhook($request));
    }
}
