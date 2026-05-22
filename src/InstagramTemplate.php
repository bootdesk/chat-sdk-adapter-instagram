<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Instagram;

use BootDesk\ChatSDK\Core\Template;

class InstagramTemplate extends Template
{
    private ?string $templateType = null;

    private ?string $text = null;

    private array $elements = [];

    private ?array $buttons = null;

    private ?array $media = null;

    private ?array $receipt = null;

    private ?string $productId = null;

    private ?array $coupon = null;

    private ?array $feedback = null;

    private ?array $notificationMessages = null;

    public static function create(string $name, string $language = 'en_US'): self
    {
        return new self($name, $language);
    }

    public function buttonTemplate(string $text, array $buttons): self
    {
        $this->templateType = 'button';
        $this->text = $text;
        $this->buttons = $buttons;

        return $this;
    }

    public function genericTemplate(array $elements): self
    {
        $this->templateType = 'generic';
        $this->elements = $elements;

        return $this;
    }

    public function mediaTemplate(string $url, string $mediaType, ?array $button = null): self
    {
        $this->templateType = 'media';
        $this->media = [
            'url' => $url,
            'media_type' => $mediaType,
            'button' => $button,
        ];

        return $this;
    }

    public function receiptTemplate(
        string $recipientName,
        string $orderNumber,
        string $currency,
        string $paymentMethod,
        string $orderUrl,
        array $elements,
        ?array $summary = null,
        ?array $adjustments = null,
        ?string $timestamp = null,
    ): self {
        $this->templateType = 'receipt';
        $this->receipt = [
            'recipient_name' => $recipientName,
            'order_number' => $orderNumber,
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'order_url' => $orderUrl,
            'elements' => $elements,
            'summary' => $summary,
            'adjustments' => $adjustments,
            'timestamp' => $timestamp,
        ];

        return $this;
    }

    public function productTemplate(string $productId): self
    {
        $this->templateType = 'product';
        $this->productId = $productId;

        return $this;
    }

    public function couponTemplate(
        string $title,
        string $code,
        ?string $redeemUrl = null,
        ?string $redeemButtonLabel = null,
        ?string $imageUrl = null,
        ?string $subtitle = null,
    ): self {
        $this->templateType = 'coupon';
        $this->coupon = array_filter([
            'title' => $title,
            'code' => $code,
            'redeem_url' => $redeemUrl,
            'redeem_button_label' => $redeemButtonLabel,
            'image_url' => $imageUrl,
            'subtitle' => $subtitle,
        ], fn (?string $v): bool => $v !== null);

        return $this;
    }

    public function customerFeedbackTemplate(
        string $title,
        string $businessAddress,
        array $ratingOptions,
        ?string $followUpAction = null,
        ?string $feedbackPrivacyUrl = null,
    ): self {
        $this->templateType = 'customer_feedback';
        $this->feedback = array_filter([
            'title' => $title,
            'business_address' => ['street_1' => $businessAddress],
            'rating_options' => $ratingOptions,
            'follow_up_action' => $followUpAction,
            'feedback_privacy_url' => $feedbackPrivacyUrl,
        ], fn (string|array|null $v): bool => $v !== null);

        return $this;
    }

    public function notificationMessagesTemplate(
        string $imageUrl,
        string $title,
        string $payload,
        string $frequency = 'DAILY',
        string $ctaText = 'GET_UPDATES',
    ): self {
        $this->templateType = 'notification_messages';
        $this->notificationMessages = [
            'image_url' => $imageUrl,
            'title' => $title,
            'payload' => $payload,
            'notification_messages_frequency' => $frequency,
            'notification_messages_cta_text' => $ctaText,
        ];

        return $this;
    }

    public function addPostbackButton(string $title, string $payload): self
    {
        $this->buttons[] = ['type' => 'postback', 'title' => $title, 'payload' => $payload];

        return $this;
    }

    public function addWebUrlButton(string $title, string $url, ?string $webviewHeightRatio = null): self
    {
        $button = ['type' => 'web_url', 'title' => $title, 'url' => $url];
        if ($webviewHeightRatio !== null) {
            $button['webview_height_ratio'] = $webviewHeightRatio;
        }

        $this->buttons[] = $button;

        return $this;
    }

    public function addPhoneNumberButton(string $title, string $phoneNumber): self
    {
        $this->buttons[] = ['type' => 'phone_number', 'title' => $title, 'payload' => $phoneNumber];

        return $this;
    }

    public function addAccountLink(string $url): self
    {
        $this->buttons[] = ['type' => 'account_link', 'url' => $url];

        return $this;
    }

    public function addAccountUnlink(): self
    {
        $this->buttons[] = ['type' => 'account_unlink'];

        return $this;
    }

    public function addGamePlayButton(string $title, string $payload, ?array $gameMetadata = null): self
    {
        $button = ['type' => 'game_play', 'title' => $title, 'payload' => $payload];
        if ($gameMetadata !== null) {
            $button['game_metadata'] = $gameMetadata;
        }

        $this->buttons[] = $button;

        return $this;
    }

    public function toInstagram(): array
    {
        $payload = match ($this->templateType) {
            'button' => $this->buildButtonPayload(),
            'generic' => $this->buildGenericPayload(),
            'media' => $this->buildMediaPayload(),
            'receipt' => $this->buildReceiptPayload(),
            'product' => $this->buildProductPayload(),
            'coupon' => $this->buildCouponPayload(),
            'customer_feedback' => $this->buildFeedbackPayload(),
            'notification_messages' => $this->buildNotificationMessagesPayload(),
            default => throw new \RuntimeException("Unknown Instagram template type: {$this->templateType}"),
        };

        return [
            'type' => 'template',
            'attachment' => [
                'type' => 'template',
                'payload' => $payload,
            ],
        ];
    }

    public function __toString(): string
    {
        return $this->text ?? $this->getName();
    }

    public function toArray(): array
    {
        return [
            'template_type' => $this->templateType,
            'name' => $this->getName(),
        ];
    }

    private function buildButtonPayload(): array
    {
        return [
            'template_type' => 'button',
            'text' => $this->text,
            'buttons' => $this->buttons ?? [],
        ];
    }

    private function buildGenericPayload(): array
    {
        return [
            'template_type' => 'generic',
            'elements' => $this->elements,
        ];
    }

    private function buildMediaPayload(): array
    {
        $payload = [
            'template_type' => 'media',
            'elements' => [
                [
                    'media_type' => $this->media['media_type'],
                    'url' => $this->media['url'],
                ],
            ],
        ];

        if ($this->media['button'] !== null) {
            $payload['elements'][0]['buttons'] = [$this->media['button']];
        }

        return $payload;
    }

    private function buildReceiptPayload(): array
    {
        $payload = [
            'template_type' => 'receipt',
            'recipient_name' => $this->receipt['recipient_name'],
            'order_number' => $this->receipt['order_number'],
            'currency' => $this->receipt['currency'],
            'payment_method' => $this->receipt['payment_method'],
            'order_url' => $this->receipt['order_url'],
            'elements' => $this->receipt['elements'],
        ];

        if ($this->receipt['summary'] !== null) {
            $payload['summary'] = $this->receipt['summary'];
        }

        if ($this->receipt['adjustments'] !== null) {
            $payload['adjustments'] = $this->receipt['adjustments'];
        }

        if ($this->receipt['timestamp'] !== null) {
            $payload['timestamp'] = $this->receipt['timestamp'];
        }

        return $payload;
    }

    private function buildProductPayload(): array
    {
        return [
            'template_type' => 'product',
            'elements' => [
                ['id' => $this->productId],
            ],
        ];
    }

    private function buildCouponPayload(): array
    {
        return [
            'template_type' => 'coupon',
            ...$this->coupon,
        ];
    }

    private function buildFeedbackPayload(): array
    {
        return [
            'template_type' => 'customer_feedback',
            ...$this->feedback,
        ];
    }

    private function buildNotificationMessagesPayload(): array
    {
        return [
            'template_type' => 'notification_messages',
            'elements' => [$this->notificationMessages],
        ];
    }
}
