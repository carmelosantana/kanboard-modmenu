<?php

use PHPUnit\Framework\TestCase;

class StylesheetTest extends TestCase
{
    // A rule that paints a background must also set its text colour, or a dark theme's light body text lands on it.
    public function testEveryBadgeBackgroundSetsItsOwnColour()
    {
        $css = file_get_contents(__DIR__.'/../Assets/css/modmenu.css');
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $checked = 0;
        foreach ($rules as [, $selector, $body]) {
            if (str_contains($selector, 'modmenu-badge') && preg_match('/(^|;)\s*background(-color)?\s*:/', $body)) {
                $checked++;
                $this->assertMatchesRegularExpression('/(^|;)\s*color\s*:/', $body, trim($selector).' sets a background without a text colour');
            }
        }
        $this->assertGreaterThan(0, $checked);
    }
}
