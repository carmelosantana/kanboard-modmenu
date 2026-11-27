<?php

use Kanboard\Plugin\ModMenu\Model\SourceUrl;
use PHPUnit\Framework\TestCase;

class SourceUrlTest extends TestCase
{
    public function testCanonicalNormalisesSchemeHostAndDefaultPort(): void
    {
        foreach ([
            'HTTPS://RAW.githubusercontent.com:443/o/r/main/p.json' => 'https://raw.githubusercontent.com/o/r/main/p.json',
            'https://Example.com:8443/p.json' => 'https://example.com:8443/p.json',
        ] as $url => $canonical) {
            $this->assertSame($canonical, SourceUrl::canonical($url), $url);
        }
    }

    public function testCanonicalKeepsPathAndQueryBytes(): void
    {
        foreach ([
            'https://example.com/A%2Fb/?x=1',
            'https://example.com/plugins/',
            'https://example.com/Plugins.JSON',
        ] as $url) {
            $this->assertSame($url, SourceUrl::canonical($url), $url);
        }
    }

    public function testCanonicalRejects(): void
    {
        foreach ([
            'http://example.com/p.json',
            'https://u:p@h/x',
            'https://h/x#f',
            'https:///x',
            'not a url',
            "https://h/a\x01b",
            'https://ex ample.com/x',
            "https://h/x\x7f",
            'https://h:0/x',
            'HTTP://h/x',
            'https://u@h/x',
            'https://h/x#',
        ] as $url) {
            $this->assertNull(SourceUrl::canonical($url), var_export($url, true));
        }
    }

    public function testIdIsSha256OfCanonicalUrl(): void
    {
        $canonical = 'https://example.com/p.json';

        $this->assertSame(hash('sha256', $canonical), SourceUrl::id($canonical));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SourceUrl::id($canonical));
    }
}
