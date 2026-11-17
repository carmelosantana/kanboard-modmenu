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

    private function rules(): array
    {
        preg_match_all('/([^{}]+)\{([^}]*)\}/', file_get_contents(__DIR__.'/../Assets/css/modmenu.css'), $rules, PREG_SET_ORDER);
        return $rules;
    }

    // A callout that paints a tinted background needs an edge and a readable text colour in both themes.
    public function testEveryCalloutBackgroundSetsABorderAndAColour()
    {
        $checked = 0;
        foreach ($this->rules() as [, $selector, $body]) {
            if (preg_match('/modmenu-(callout|dep)/', $selector) && preg_match('/(^|;)\s*background(-color)?\s*:/', $body)) {
                $checked++;
                $this->assertMatchesRegularExpression('/(^|;)\s*border(-left)?\s*:/', $body, trim($selector).' sets a background without a border');
                $this->assertMatchesRegularExpression('/(^|;)\s*color\s*:/', $body, trim($selector).' sets a background without a text colour');
            }
        }
        $this->assertGreaterThanOrEqual(2, $checked, 'required and conflict callouts both paint a background');
    }

    public function testRequiredCalloutHasALeftAccent()
    {
        foreach ($this->rules() as [, $selector, $body]) {
            if (str_contains($selector, 'modmenu-dep--required') && str_contains($body, 'background')) {
                $this->assertMatchesRegularExpression('/border-left\s*:\s*3px/', $body);
                return;
            }
        }
        $this->fail('no background rule for .modmenu-dep--required');
    }

    public function testScreenshotThumbnailsAre16By10Covers()
    {
        foreach ($this->rules() as [, $selector, $body]) {
            if (trim($selector) === '.modmenu-shot') {
                $this->assertMatchesRegularExpression('/aspect-ratio\s*:\s*16\s*\/\s*10/', $body);
                $this->assertMatchesRegularExpression('/object-fit\s*:\s*cover/', $body);
                return;
            }
        }
        $this->fail('no .modmenu-shot rule');
    }
}
