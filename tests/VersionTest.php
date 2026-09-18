<?php

declare(strict_types=1);

namespace Bfocus\Tests;

use Bfocus\Bfocus;
use Bfocus\Tests\Support\Cases;
use PHPUnit\Framework\TestCase;

/**
 * Versão: no PHP o manifesto (composer.json) NÃO leva versão — o Packagist a tira da tag git.
 * A fonte única é `Bfocus::VERSION`; o `release-sdks.sh` a bumpa por regex e o publish.yml
 * trava tag == VERSION. Aqui travamos o formato de que os dois dependem.
 */
final class VersionTest extends TestCase
{
    public function testVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Bfocus::VERSION);
        $this->assertSame('bfocus-php/' . Bfocus::VERSION, Bfocus::CLIENT_ID);
    }

    public function testVersionLineMatchesReleaseScriptRegex(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Bfocus.php');

        // mesmo padrão do scripts/release-sdks.sh (sub com count=1)
        $this->assertSame(1, preg_match_all("/public const VERSION = '([^']+)'/", $source, $m));
        $this->assertSame(Bfocus::VERSION, $m[1][0]);
    }

    public function testManifestTakesVersionFromTag(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('bfocus/bfocus', $manifest['name']);
        $this->assertArrayNotHasKey('version', $manifest, 'a versão vem da tag git (vX.Y.Z == Bfocus::VERSION)');
        $this->assertSame('Bfocus\\', array_key_first($manifest['autoload']['psr-4']));
    }

    public function testReleaseTagMatchesVersionWhenGiven(): void
    {
        $tag = getenv('BFOCUS_RELEASE_TAG');
        if (!is_string($tag) || $tag === '') {
            $this->markTestSkipped('BFOCUS_RELEASE_TAG não definido (só no publish.yml).');
        }
        $this->assertSame('v' . Bfocus::VERSION, $tag);
    }

    public function testVendoredCasesMatchSource(): void
    {
        if (!is_file(Cases::sourcePath())) {
            $this->markTestSkipped('fora do monorepo: só existe a cópia tests/conformance/cases.json.');
        }
        $this->assertFileEquals(
            Cases::sourcePath(),
            Cases::vendoredPath(),
            'tests/conformance/cases.json desatualizado: cp ../conformance/cases.json tests/conformance/cases.json',
        );
    }
}
