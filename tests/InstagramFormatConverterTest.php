<?php

namespace BootDesk\ChatSDK\Instagram\Tests;

use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Instagram\InstagramFormatConverter;
use PHPUnit\Framework\TestCase;

class InstagramFormatConverterTest extends TestCase
{
    private InstagramFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new InstagramFormatConverter;
    }

    public function test_passthrough_text(): void
    {
        $ast = $this->converter->toAst('Hello world');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('Hello world', $markdown);
    }

    public function test_bold_conversion(): void
    {
        $ast = $this->converter->toAst('This is **bold** text');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_render_postable_text(): void
    {
        $message = PostableMessage::text('Hello');
        $this->assertSame('Hello', $this->converter->renderPostable($message));
    }
}
