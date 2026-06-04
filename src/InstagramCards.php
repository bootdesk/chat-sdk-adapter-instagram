<?php

namespace BootDesk\ChatSDK\Instagram;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Cards\Divider;
use BootDesk\ChatSDK\Core\Cards\Image;
use BootDesk\ChatSDK\Core\Cards\Link;
use BootDesk\ChatSDK\Core\Cards\LinkButton;
use BootDesk\ChatSDK\Core\Cards\Table;
use BootDesk\ChatSDK\Core\Cards\Text;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text as CommonMarkText;
use League\CommonMark\Parser\MarkdownParser;

class InstagramCards
{
    private const CALLBACK_PREFIX = 'chat:';

    private const MAX_BUTTONS = 3;

    private const MAX_BUTTON_TITLE = 20;

    public static function toInstagramPayload(Card $card): array
    {
        $buttons = $card->getButtons();
        $linkButtons = $card->getLinkButtons();

        $allButtons = array_merge($buttons, $linkButtons);

        if ($allButtons !== [] && count($allButtons) <= self::MAX_BUTTONS) {
            $allFit = true;
            $instagramButtons = [];
            foreach (array_slice($allButtons, 0, self::MAX_BUTTONS) as $button) {
                if (strlen($button->label) > self::MAX_BUTTON_TITLE) {
                    $allFit = false;
                    break;
                }
                $instagramButtons[] = $button instanceof LinkButton
                    ? self::convertLinkButton($button)
                    : self::convertButton($button);
            }

            if ($allFit && $instagramButtons !== []) {
                $header = $card->getHeader();
                if ($header !== null) {
                    return self::buildGenericTemplate($card, $instagramButtons);
                }

                $bodyText = self::buildBodyText($card);
                if ($bodyText !== '') {
                    return self::buildButtonTemplate($bodyText, $instagramButtons);
                }
            }
        }

        return [
            'type' => 'text',
            'text' => self::cardToText($card),
        ];
    }

    public static function encodeCallbackData(string $actionId, ?string $value = null): string
    {
        $payload = ['a' => $actionId];
        if ($value !== null) {
            $payload['v'] = $value;
        }

        return self::CALLBACK_PREFIX.json_encode($payload);
    }

    public static function decodeCallbackData(?string $data): array
    {
        if ($data === null || $data === '') {
            return ['actionId' => 'instagram_callback', 'value' => null];
        }

        if (! str_starts_with($data, self::CALLBACK_PREFIX)) {
            return ['actionId' => $data, 'value' => $data];
        }

        $json = substr($data, strlen(self::CALLBACK_PREFIX));

        $decoded = json_decode($json, true);
        if (is_array($decoded) && isset($decoded['a']) && is_string($decoded['a'])) {
            return [
                'actionId' => $decoded['a'],
                'value' => $decoded['v'] ?? null,
            ];
        }

        return ['actionId' => $data, 'value' => $data];
    }

    public static function cardToText(Card $card): string
    {
        $lines = [];

        if ($card->getImageUrl() !== null) {
            $lines[] = $card->getImageUrl();
        }

        if ($card->getHeader() !== null) {
            $lines[] = $card->getHeader();
        }

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $lines[] = $child->content;
            } elseif ($child instanceof Divider) {
                $lines[] = '---';
            } elseif ($child instanceof Image) {
                $lines[] = $child->alt !== '' ? "{$child->alt}: {$child->url}" : $child->url;
            } elseif ($child instanceof Link) {
                $lines[] = "[{$child->label}]({$child->url})";
            } elseif ($child instanceof Table) {
                $lines[] = self::renderTableAsText($child);
            } elseif ($child instanceof LinkButton) {
                $lines[] = "[{$child->label}]({$child->url})";
            }
        }

        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $lines[] = self::markdownToPlainText($section->getText());
            }

            foreach ($section->getFields() as $label => $value) {
                $lines[] = "{$label}: {$value}";
            }
        }

        $allButtons = array_merge($card->getButtons(), $card->getLinkButtons());
        foreach ($allButtons as $button) {
            $lines[] = "[{$button->label}]";
        }

        return implode("\n", $lines);
    }

    private static function buildGenericTemplate(Card $card, array $buttons): array
    {
        $parts = [];
        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $parts[] = $child->content;
            } elseif ($child instanceof Link) {
                $parts[] = "[{$child->label}]({$child->url})";
            } elseif ($child instanceof Table) {
                $parts[] = self::renderTableAsText($child);
            } elseif ($child instanceof Image) {
                $parts[] = $child->alt !== '' ? "{$child->alt}: {$child->url}" : $child->url;
            }
        }
        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $parts[] = self::markdownToPlainText($section->getText());
            }
        }
        $subtitle = implode("\n", $parts);

        $element = [
            'title' => self::truncate($card->getHeader() ?? 'Menu', 80),
            'buttons' => $buttons,
        ];

        if ($subtitle !== '') {
            $element['subtitle'] = self::truncate($subtitle, 80);
        }

        if ($card->getImageUrl() !== null) {
            $element['image_url'] = $card->getImageUrl();
        }

        return [
            'type' => 'template',
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'generic',
                    'elements' => [$element],
                ],
            ],
        ];
    }

    private static function buildButtonTemplate(string $text, array $buttons): array
    {
        return [
            'type' => 'template',
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text' => self::truncate($text, 640),
                    'buttons' => $buttons,
                ],
            ],
        ];
    }

    private static function buildBodyText(Card $card): string
    {
        $parts = [];

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $parts[] = $child->content;
            } elseif ($child instanceof Image) {
                $parts[] = $child->alt !== '' ? "{$child->alt}: {$child->url}" : $child->url;
            } elseif ($child instanceof Link) {
                $parts[] = "[{$child->label}]({$child->url})";
            } elseif ($child instanceof Table) {
                $parts[] = self::renderTableAsText($child);
            } elseif ($child instanceof LinkButton) {
                $parts[] = "[{$child->label}]({$child->url})";
            }
        }

        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $parts[] = self::markdownToPlainText($section->getText());
            }
        }

        return implode("\n", $parts);
    }

    private static function convertButton(Button $button): array
    {
        $url = $button->data['url'] ?? null;
        $phoneNumber = $button->data['phone_number'] ?? null;

        if ($url !== null) {
            $result = [
                'type' => 'web_url',
                'title' => self::truncate($button->label, self::MAX_BUTTON_TITLE),
                'url' => $url,
            ];
            if (isset($button->data['webview_height_ratio'])) {
                $result['webview_height_ratio'] = $button->data['webview_height_ratio'];
            }

            return $result;
        }

        if ($phoneNumber !== null) {
            return [
                'type' => 'phone_number',
                'title' => self::truncate($button->label, self::MAX_BUTTON_TITLE),
                'payload' => $phoneNumber,
            ];
        }

        return [
            'type' => 'postback',
            'title' => self::truncate($button->label, self::MAX_BUTTON_TITLE),
            'payload' => self::encodeCallbackData($button->actionId, json_encode($button->data) ?: null),
        ];
    }

    private static function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 1)."\u{2026}";
    }

    private static function convertLinkButton(LinkButton $button): array
    {
        return [
            'type' => 'web_url',
            'title' => self::truncate($button->label, self::MAX_BUTTON_TITLE),
            'url' => $button->url,
        ];
    }

    private static function markdownToPlainText(string $markdown): string
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $parser = new MarkdownParser($environment);
        $ast = $parser->parse($markdown);

        $walker = $ast->walker();
        $result = '';

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if ($event->isEntering()) {
                if ($node instanceof CommonMarkText) {
                    $result .= $node->getLiteral();
                }
            } elseif ($node instanceof Paragraph) {
                $result .= "\n";
            }
        }

        return trim($result);
    }

    private static function renderTableAsText(Table $table): string
    {
        return Table::renderAsText($table);
    }
}
