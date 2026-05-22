# adapter-instagram

Instagram DM adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Instagram`

## files
- `InstagramAdapter` — implements `Adapter` using Instagram Messaging API
- `InstagramFormatConverter` — Instagram text ↔ CommonMark AST
- `InstagramCards` — Card model → Instagram Generic Template / Button Template
- `InstagramTemplate` — structured message template builder
- `InstagramWebhookVerifier` — verify_token challenge + HMAC signature

## registration
`src/register.php` registers `'instagram' => InstagramAdapter::class` via `AdapterRegistry`

## constructor
```php
new InstagramAdapter(
    string $pageAccessToken,
    string $appSecret,
    string $verifyToken,
    ClientInterface $httpClient,
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format
`instagram:{recipientId}` — e.g. `instagram:987654321`

## webhook flow
1. `verifyWebhook` — responds to `hub.verify_token` challenge; verifies HMAC-SHA256 on POST
2. `parseWebhook` — extracts user messages (skips echo)
3. `parseAction` — extracts `messaging_postbacks` (postback buttons, Get Started, persistent menu); implements `HandlesActions`
4. `parseSlashCommand` — extracts messages starting with `/`; implements `HandlesSlashCommands`
5. `parseReaction` — extracts `message_reactions` (react/unreact); implements `HandlesReactions`
6. `parseStatus` — extracts `message_deliveries` and `message_reads`; implements `HandlesStatuses`

## features
- Send text, generic templates, button templates, quick replies
- Sender Actions (typing_on, typing_off, mark_seen)
- Fetch user profile (first_name, last_name, profile_pic)
- Slash commands (`/command`) with arguments
- Reactions (emoji react/unreact)
- No message editing/deletion support
- Streaming: concatenates chunks into single message
- Batched webhook support (multiple events per request)

## config (laravel)
```php
'instagram' => [
    'page_access_token' => env('INSTAGRAM_PAGE_ACCESS_TOKEN'),
    'app_secret' => env('INSTAGRAM_APP_SECRET'),
    'verify_token' => env('INSTAGRAM_VERIFY_TOKEN'),
],
```
