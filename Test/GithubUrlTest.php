<?php

use Kanboard\Plugin\ModMenu\Model\GithubUrl;
use Kanboard\Plugin\ModMenu\Model\SourceUrl;
use PHPUnit\Framework\TestCase;

class GithubUrlTest extends TestCase
{
    private const RAW = 'https://raw.githubusercontent.com/owner/repo/main/plugins.json';

    public function testSourceAcceptsAndCanonicalises(): void
    {
        $source = GithubUrl::source('https://raw.githubusercontent.com/Owner/Repo/v1.0+b/dir/plugins.json');
        $endpoint = 'https://raw.githubusercontent.com/owner/repo/v1.0%2Bb/dir/plugins.json';

        $this->assertSame([
            'owner' => 'owner',
            'repo' => 'repo',
            'ref' => 'v1.0+b',
            'path' => ['dir', 'plugins.json'],
            'endpoint' => $endpoint,
            'id' => SourceUrl::id($endpoint),
        ], $source);

        $plain = GithubUrl::source(self::RAW);
        $this->assertSame(self::RAW, $plain['endpoint']);

        foreach ([
            'https://raw.githubusercontent.com/owner/repo/%6dain/plugins.json',
            'https://raw.githubusercontent.com/Owner/Repo/main/plugins.json',
            'HTTPS://RAW.GitHubUserContent.com/owner/repo/main/plugins.json',
        ] as $url) {
            $this->assertSame($plain['id'], GithubUrl::source($url)['id'] ?? null, $url);
        }
    }

    public function testSourceRejectsTricks(): void
    {
        foreach ([
            'userinfo' => 'https://u:p@raw.githubusercontent.com/owner/repo/main/plugins.json',
            'user only' => 'https://u@raw.githubusercontent.com/owner/repo/main/plugins.json',
            'port 443' => 'https://raw.githubusercontent.com:443/owner/repo/main/plugins.json',
            'port 8443' => 'https://raw.githubusercontent.com:8443/owner/repo/main/plugins.json',
            'trailing dot' => 'https://raw.githubusercontent.com./owner/repo/main/plugins.json',
            'suffix host' => 'https://raw.githubusercontent.com.evil.test/owner/repo/main/plugins.json',
            'prefix host' => 'https://evil-raw.githubusercontent.com/owner/repo/main/plugins.json',
            'punycode' => 'https://xn--rw-7mc.githubusercontent.com/owner/repo/main/plugins.json',
            'cyrillic a' => 'https://rаw.githubusercontent.com/owner/repo/main/plugins.json',
            'percent in host' => 'https://raw.githubusercontent%2Ecom/owner/repo/main/plugins.json',
            'ip host' => 'https://185.199.108.133/owner/repo/main/plugins.json',
            'encoded slash' => 'https://raw.githubusercontent.com/owner/repo/main%2Fx/plugins.json',
            'double-encoded slash' => 'https://raw.githubusercontent.com/owner/repo/main%252Fx/plugins.json',
            'backslash' => 'https://raw.githubusercontent.com/owner/repo/main\\x/plugins.json',
            'encoded backslash' => 'https://raw.githubusercontent.com/owner/repo/main%5Cx/plugins.json',
            'nul' => 'https://raw.githubusercontent.com/owner/repo/main%00/plugins.json',
            'raw control' => "https://raw.githubusercontent.com/owner/repo/main\x01/plugins.json",
            'space' => 'https://raw.githubusercontent.com/owner/repo/ma in/plugins.json',
            'encoded space' => 'https://raw.githubusercontent.com/owner/repo/ma%20in/plugins.json',
            'dot-dot' => 'https://raw.githubusercontent.com/owner/repo/main/../plugins.json',
            'encoded dot-dot' => 'https://raw.githubusercontent.com/owner/repo/main/%2e%2e/plugins.json',
            'dot' => 'https://raw.githubusercontent.com/owner/repo/main/./plugins.json',
            'dot-dot in ref' => 'https://raw.githubusercontent.com/owner/repo/a..b/plugins.json',
            'dot repo' => 'https://raw.githubusercontent.com/owner/../main/plugins.json',
            'empty segment' => 'https://raw.githubusercontent.com/owner/repo/main//plugins.json',
            'trailing slash' => 'https://raw.githubusercontent.com/owner/repo/main/plugins.json/',
            'query' => self::RAW.'?x',
            'empty query' => self::RAW.'?',
            'fragment' => self::RAW.'#x',
            'not json' => 'https://raw.githubusercontent.com/owner/repo/main/plugins.txt',
            'no path' => 'https://raw.githubusercontent.com/owner/repo/main',
            'no file' => 'https://raw.githubusercontent.com/owner/repo/plugins.json',
            'http' => 'http://raw.githubusercontent.com/owner/repo/main/plugins.json',
            'no slashes' => 'https:raw.githubusercontent.com/owner/repo/main/plugins.json',
            'owner leading dash' => 'https://raw.githubusercontent.com/-owner/repo/main/plugins.json',
            'owner too long' => 'https://raw.githubusercontent.com/'.str_repeat('a', 40).'/repo/main/plugins.json',
            'non-ascii path' => 'https://raw.githubusercontent.com/owner/repo/main/plügins.json',
            'github.com host' => 'https://github.com/owner/repo/main/plugins.json',
            'api host' => 'https://api.github.com/owner/repo/main/plugins.json',
            'empty' => '',
        ] as $case => $url) {
            $this->assertNull(GithubUrl::source($url), $case);
        }
    }

    public function testDownloadParses(): void
    {
        $this->assertSame(
            ['owner' => 'owner', 'repo' => 'repo', 'tag' => 'v1.0+b', 'asset' => 'Plugin-1.0.zip'],
            GithubUrl::download('https://github.com/Owner/Repo/releases/download/v1.0%2Bb/Plugin-1.0.zip')
        );
    }

    public function testDownloadRejects(): void
    {
        foreach ([
            'wrong host' => 'https://api.github.com/owner/repo/releases/download/v1/p.zip',
            'raw host' => 'https://raw.githubusercontent.com/owner/repo/releases/download/v1/p.zip',
            'missing asset' => 'https://github.com/owner/repo/releases/download/v1',
            'missing tag' => 'https://github.com/owner/repo/releases/download//p.zip',
            'extra segment' => 'https://github.com/owner/repo/releases/download/v1/x/p.zip',
            'dot-dot tag' => 'https://github.com/owner/repo/releases/download/../p.zip',
            'dot-dot in tag' => 'https://github.com/owner/repo/releases/download/v1..2/p.zip',
            'not download' => 'https://github.com/owner/repo/releases/latest/v1/p.zip',
            'query' => 'https://github.com/owner/repo/releases/download/v1/p.zip?x=1',
            'port' => 'https://github.com:443/owner/repo/releases/download/v1/p.zip',
            'http' => 'http://github.com/owner/repo/releases/download/v1/p.zip',
            'encoded slash asset' => 'https://github.com/owner/repo/releases/download/v1/a%2Fp.zip',
        ] as $case => $url) {
            $this->assertNull(GithubUrl::download($url), $case);
        }
    }

    public function testApiUrlsAreBuiltEncoded(): void
    {
        $this->assertSame(
            'https://api.github.com/repos/owner/repo/releases/tags/v1.0%2Bb',
            GithubUrl::releaseByTagUrl('owner', 'repo', 'v1.0+b')
        );
        $this->assertSame(
            'https://api.github.com/repos/owner/repo/releases/assets/42',
            GithubUrl::assetUrl('owner', 'repo', 42)
        );
    }

    public function testTokenHostOnlyApiAndRaw(): void
    {
        foreach ([
            self::RAW,
            GithubUrl::releaseByTagUrl('owner', 'repo', 'v1.0+b'),
            GithubUrl::assetUrl('owner', 'repo', 42),
        ] as $url) {
            $this->assertTrue(GithubUrl::tokenHost($url), $url);
        }

        foreach ([
            'https://github.com/owner/repo/releases/download/v1/p.zip',
            'https://objects.githubusercontent.com/github-production-release-asset/1/2?sig=x',
            'https://api.github.com:443/repos/owner/repo/releases/assets/42',
            'https://api.github.com./repos/owner/repo/releases/assets/42',
            'https://u@api.github.com/repos/owner/repo/releases/assets/42',
            'https://api.github.com/repos/owner/repo/releases/assets/42?x=1',
            'https://api.github.com/repos/owner/repo/releases/assets/0',
            'https://api.github.com/repos/owner/repo/releases/assets/4a',
            'https://api.github.com/repos/owner/repo/releases/tags/a..b',
            'https://api.github.com/user',
            'http://api.github.com/repos/owner/repo/releases/assets/42',
            'https://raw.githubusercontent.com/owner/repo/main/plugins.json#x',
            'https://evil.test/plugins.json',
        ] as $url) {
            $this->assertFalse(GithubUrl::tokenHost($url), $url);
        }
    }
}
