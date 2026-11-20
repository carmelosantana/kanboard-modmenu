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
        $this->assertGreaterThanOrEqual(4, $checked, 'required, conflict, met and outdated callouts all paint a background');
    }

    /** Body of the background rule for a callout modifier. */
    private function calloutRule(string $modifier): string
    {
        foreach ($this->rules() as [, $selector, $body]) {
            if (trim($selector) === '.' . $modifier && str_contains($body, 'background')) {
                return $body;
            }
        }
        $this->fail("no background rule for .$modifier");
    }

    /** Body of the rule colouring a callout modifier's icon. */
    private function iconRule(string $modifier): string
    {
        foreach ($this->rules() as [, $selector, $body]) {
            if (preg_match('/^\.' . preg_quote($modifier, '/') . '\s*>\s*\.fa$/', trim($selector))) {
                return $body;
            }
        }
        $this->fail("no icon rule for .$modifier > .fa");
    }

    public function testEveryRequirementCalloutHasALeftAccentAndInheritsText()
    {
        foreach (['modmenu-dep--required', 'modmenu-dep--conflict', 'modmenu-dep--met', 'modmenu-dep--outdated'] as $modifier) {
            $body = $this->calloutRule($modifier);
            $this->assertMatchesRegularExpression('/border-left\s*:\s*3px/', $body, $modifier);
            $this->assertMatchesRegularExpression('/(^|;)\s*color\s*:\s*inherit/', $body, $modifier . ' keeps the theme foreground');
        }
    }

    public function testMetCalloutIsGreen()
    {
        $body = $this->calloutRule('modmenu-dep--met');
        $this->assertMatchesRegularExpression('/background\s*:\s*rgba\(\s*22,\s*163,\s*74,\s*0?\.10?\s*\)/', $body);
        $this->assertMatchesRegularExpression('/border\s*:\s*1px solid rgba\(\s*22,\s*163,\s*74,\s*0?\.35\s*\)/', $body);
        $this->assertMatchesRegularExpression('/border-left\s*:\s*3px solid #16a34a/', $body);
        $this->assertMatchesRegularExpression('/color\s*:\s*#16a34a/', $this->iconRule('modmenu-dep--met'));
    }

    public function testOutdatedCalloutReusesTheConflictAmber()
    {
        $strip = static fn (string $b) => preg_replace('/\s+/', '', $b);
        $this->assertSame($strip($this->calloutRule('modmenu-dep--conflict')), $strip($this->calloutRule('modmenu-dep--outdated')));
        $this->assertSame($strip($this->iconRule('modmenu-dep--conflict')), $strip($this->iconRule('modmenu-dep--outdated')));
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
