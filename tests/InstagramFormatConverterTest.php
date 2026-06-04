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

    public function test_plain_text(): void
    {
        $ast = $this->converter->toAst('Hello world');
        $result = $this->converter->fromAst($ast);
        $this->assertSame('Hello world', $result);
    }

    public function test_bold_from_markdown(): void
    {
        $ast = $this->converter->toAst('**bold**');
        $result = $this->converter->fromAst($ast);
        $this->assertSame('*bold*', $result);
    }

    public function test_italic_from_markdown(): void
    {
        $result = $this->converter->fromMarkdown('*italic*');
        $this->assertSame('_italic_', $result);
    }

    public function test_strikethrough_from_markdown(): void
    {
        $ast = $this->converter->toAst('~~strike~~');
        $result = $this->converter->fromAst($ast);
        $this->assertSame('~strike~', $result);
    }

    public function test_inline_code(): void
    {
        $ast = $this->converter->toAst('`code`');
        $result = $this->converter->fromAst($ast);
        $this->assertSame('`code`', $result);
    }

    public function test_link_stripped_to_text(): void
    {
        $ast = $this->converter->toAst('[text](https://example.com)');
        $result = $this->converter->fromAst($ast);
        $this->assertSame('text', $result);
    }

    public function test_instagram_bold_to_ast(): void
    {
        $ast = $this->converter->toAst('*bold*');
        $result = $this->converter->fromAst($ast);
        $this->assertSame('*bold*', $result);
    }

    public function test_instagram_italic_to_ast(): void
    {
        $ast = $this->converter->toAst('_italic_');
        $result = $this->converter->fromAst($ast);
        $this->assertSame('_italic_', $result);
    }

    public function test_mixed_formatting(): void
    {
        $result = $this->converter->fromMarkdown('**bold** and *italic* and ~~strike~~');
        $this->assertSame('*bold* and _italic_ and ~strike~', $result);
    }

    public function test_table_preserved_as_pipe_text(): void
    {
        $result = $this->converter->fromMarkdown("| Name | Age |\n| --- | --- |\n| Alice | 30 |\n| Bob | 25 |");
        $this->assertStringContainsString('| Name | Age |', $result);
        $this->assertStringContainsString('| Alice | 30 |', $result);
        $this->assertStringContainsString('| Bob | 25 |', $result);
    }

    public function test_render_postable_text(): void
    {
        $message = PostableMessage::text('Hello **bold** world');
        $result = $this->converter->renderPostable($message);
        $this->assertSame('Hello *bold* world', $result);
    }
}
