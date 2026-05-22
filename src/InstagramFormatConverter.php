<?php

namespace BootDesk\ChatSDK\Instagram;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Node\Block\Document;

class InstagramFormatConverter extends BaseFormatConverter
{
    public function toAst(string $text): Document
    {
        return $this->parseMarkdown($text);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return $message->content->getFallbackText();
        }

        return (string) $message->content;
    }
}
