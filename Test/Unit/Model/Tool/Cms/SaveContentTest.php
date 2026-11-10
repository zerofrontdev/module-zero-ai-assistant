<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\AiAssistant\Test\Unit\Model\Tool\Cms;

use PHPUnit\Framework\TestCase;
use Zero\AiAssistant\Model\Tool\Cms\SaveContent;

/**
 * Uploaded photos in a page's HTML: {{upload:N}} found, then replaced by
 * the media directive once the file is published.
 */
class SaveContentTest extends TestCase
{
    public function testPlaceholdersAreFoundOnce(): void
    {
        $html = '<p>Hi</p><img src="{{upload:12}}"><img src="{{ upload: 7 }}"><img src="{{upload:12}}">';
        $this->assertSame([12, 7], SaveContent::uploadIds($html));
        $this->assertSame([], SaveContent::uploadIds('<p>{{media url="a.jpg"}} {{upload:x}}</p>'));
    }

    public function testPlaceholdersBecomeMediaDirectives(): void
    {
        $html = '<img src="{{upload:12}}" alt="Front"><img src="{{upload:7}}">';
        $this->assertSame(
            '<img src="{{media url="wysiwyg/zero-ai/front-ab12cd34.jpg"}}" alt="Front"><img src="{{upload:7}}">',
            SaveContent::withMedia($html, [12 => 'wysiwyg/zero-ai/front-ab12cd34.jpg']),
            'an id without a path is left as it is'
        );
    }
}
