<?php

namespace BootDesk\ChatSDK\Instagram\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Instagram\InstagramCards;
use PHPUnit\Framework\TestCase;

class InstagramCardsTest extends TestCase
{
    public function test_card_to_text_fallback(): void
    {
        $card = Card::make()->header('Test Title')->section(fn ($s) => $s->text('Description'));
        $result = InstagramCards::toInstagramPayload($card);

        $this->assertSame('text', $result['type']);
        $this->assertStringContainsString('Test Title', $result['text']);
    }

    public function test_generic_template_with_header_and_buttons(): void
    {
        $card = Card::make()
            ->header('Deploy Ready')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $result = InstagramCards::toInstagramPayload($card);

        $this->assertSame('template', $result['type']);
        $payload = $result['attachment']['payload'];
        $this->assertSame('generic', $payload['template_type']);
        $this->assertSame('Deploy Ready', $payload['elements'][0]['title']);
        $this->assertCount(1, $payload['elements'][0]['buttons']);
        $this->assertSame('postback', $payload['elements'][0]['buttons'][0]['type']);
        $this->assertSame('Deploy', $payload['elements'][0]['buttons'][0]['title']);
    }

    public function test_generic_template_renders_text_link_table_children(): void
    {
        $card = Card::make()
            ->header('System Status')
            ->imageUrl('https://picsum.photos/seed/status/800/200', 'status banner')
            ->text('All services are operational.')
            ->table(
                ['Service', 'Status'],
                [
                    ['API', 'Healthy'],
                    ['DB', 'Connected'],
                ],
            )
            ->link('View metrics', 'https://status.example.com')
            ->linkButton('Dashboard', 'https://dash.example.com');

        $result = InstagramCards::toInstagramPayload($card);

        $this->assertSame('template', $result['type']);
        $element = $result['attachment']['payload']['elements'][0];
        $this->assertSame('System Status', $element['title']);
        $this->assertSame('https://picsum.photos/seed/status/800/200', $element['image_url']);
        $this->assertStringContainsString('All services are operational.', $element['subtitle']);
        $this->assertStringContainsString('Service | Status', $element['subtitle']);
        $this->assertCount(1, $element['buttons']);
        $this->assertSame('Dashboard', $element['buttons'][0]['title']);
        $this->assertSame('https://dash.example.com', $element['buttons'][0]['url']);
    }

    public function test_button_template_without_header(): void
    {
        $card = Card::make()
            ->section(fn ($s) => $s->text('Choose an option'))
            ->actions([Button::primary('Yes', 'yes'), Button::secondary('No', 'no')]);

        $result = InstagramCards::toInstagramPayload($card);

        $this->assertSame('template', $result['type']);
        $payload = $result['attachment']['payload'];
        $this->assertSame('button', $payload['template_type']);
        $this->assertStringContainsString('Choose an option', $payload['text']);
        $this->assertCount(2, $payload['buttons']);
    }

    public function test_max_three_buttons(): void
    {
        $card = Card::make()
            ->header('Menu')
            ->actions([
                Button::secondary('A', 'a'),
                Button::secondary('B', 'b'),
                Button::secondary('C', 'c'),
                Button::secondary('D', 'd'),
            ]);

        $result = InstagramCards::toInstagramPayload($card);

        // 4 buttons exceeds max 3, falls back to text
        $this->assertSame('text', $result['type']);
        $this->assertStringContainsString('Menu', $result['text']);
    }

    public function test_exactly_three_buttons_is_template(): void
    {
        $card = Card::make()
            ->header('Menu')
            ->actions([
                Button::secondary('A', 'a'),
                Button::secondary('B', 'b'),
                Button::secondary('C', 'c'),
            ]);

        $result = InstagramCards::toInstagramPayload($card);

        $this->assertSame('template', $result['type']);
        $payload = $result['attachment']['payload'];
        $this->assertCount(3, $payload['elements'][0]['buttons']);
    }

    public function test_encode_callback_data(): void
    {
        $encoded = InstagramCards::encodeCallbackData('deploy', 'staging');
        $this->assertStringStartsWith('chat:', $encoded);

        $decoded = json_decode(substr($encoded, 5), true);
        $this->assertSame('deploy', $decoded['a']);
        $this->assertSame('staging', $decoded['v']);
    }

    public function test_encode_callback_data_without_value(): void
    {
        $encoded = InstagramCards::encodeCallbackData('action');
        $decoded = json_decode(substr($encoded, 5), true);
        $this->assertSame('action', $decoded['a']);
        $this->assertArrayNotHasKey('v', $decoded);
    }

    public function test_decode_callback_data(): void
    {
        $data = InstagramCards::encodeCallbackData('test', 'val');
        $result = InstagramCards::decodeCallbackData($data);

        $this->assertSame('test', $result['actionId']);
        $this->assertSame('val', $result['value']);
    }

    public function test_decode_null_data(): void
    {
        $result = InstagramCards::decodeCallbackData(null);
        $this->assertSame('instagram_callback', $result['actionId']);
    }

    public function test_decode_legacy_payload(): void
    {
        $result = InstagramCards::decodeCallbackData('CUSTOM_PAYLOAD');
        $this->assertSame('CUSTOM_PAYLOAD', $result['actionId']);
    }

    public function test_roundtrip_callback_data(): void
    {
        $encoded = InstagramCards::encodeCallbackData('action', 'value');
        $decoded = InstagramCards::decodeCallbackData($encoded);
        $this->assertSame('action', $decoded['actionId']);
        $this->assertSame('value', $decoded['value']);
    }
}
