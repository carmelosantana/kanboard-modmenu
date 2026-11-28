<?php

use Kanboard\Plugin\ModMenu\Model\PluginName;
use PHPUnit\Framework\TestCase;

class PluginNameTest extends TestCase
{
    public function testKeyAcceptsAndLowercases(): void
    {
        foreach ([
            'Foo' => 'foo',
            'Lifeboat_2' => 'lifeboat_2',
            str_repeat('A', 64) => str_repeat('a', 64),
        ] as $name => $key) {
            $this->assertSame($key, PluginName::key((string) $name), (string) $name);
        }
    }

    public function testKeyRejects(): void
    {
        foreach (['', str_repeat('a', 65), 'Foo-Bar', 'foo.bar', '../x', 'Fóo', "a\0", "foo\n"] as $name) {
            $this->assertNull(PluginName::key($name), var_export($name, true));
        }
    }
}
