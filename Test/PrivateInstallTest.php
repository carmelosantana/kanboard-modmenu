<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\CatalogLock;
use Kanboard\Plugin\ModMenu\Model\DependencyResolver;
use Kanboard\Plugin\ModMenu\Model\DirectoryClient;
use Kanboard\Plugin\ModMenu\Model\GithubUrl;
use Kanboard\Plugin\ModMenu\Model\MutationGuard;
use Kanboard\Plugin\ModMenu\Model\PluginManager;
use Kanboard\Plugin\ModMenu\Model\PrivateState;
use Kanboard\Plugin\ModMenu\Model\SourceRepository;

/** A stand-in for PrivateFetcher: listings per source id, releases per download URL. */
class PrivateInstallFakeFetcher
{
    public array $listings = [];
    public array $releases = [];
    public array $downloads = [];
    /** @var callable|null runs inside downloadRelease, before the archive is returned */
    public $during = null;

    public function fetchDirectory(array $source): array
    {
        $listing = $this->listings[$source['id']] ?? [];
        if ($listing instanceof \Throwable) {
            throw $listing;
        }
        return $listing;
    }

    public function downloadRelease(array $source, string $downloadUrl): string
    {
        $this->downloads[] = [$source, $downloadUrl];
        if ($this->during !== null) {
            ($this->during)();
        }
        if (empty($this->releases[$downloadUrl])) {
            throw new ModMenuException("Couldn't download this private plugin's release.");
        }
        $path = tempnam(sys_get_temp_dir(), 'modmenu-release-');
        copy($this->releases[$downloadUrl], $path);
        return $path;
    }
}

/** A manager that runs a test's callback at its critical point, or fails removal. */
class PrivateInstallSpyManager extends PluginManager
{
    public ?Closure $atCommit = null;
    public bool $failRemoval = false;
    public ?Closure $atRemoval = null;

    protected function beforeCommit(string $name): void
    {
        if ($this->atCommit !== null) {
            ($this->atCommit)($name);
        }
    }

    protected function removeTree(string $dir): bool
    {
        if ($this->atRemoval !== null) {
            ($this->atRemoval)($dir);
        }
        return $this->failRemoval ? false : parent::removeTree($dir);
    }
}

/** A catalog lock whose exclusive hold refuses instead of blocking (same-process interleavings). */
class PrivateInstallNonBlockingCatalogLock extends CatalogLock
{
    public const BUSY = 'catalog lock is held';

    public function __construct(private string $lockDir)
    {
        parent::__construct($lockDir);
    }

    public function exclusive(callable $fn): mixed
    {
        $path = $this->lockDir.'/'.CatalogLock::FILE;
        if (is_file($path)) {
            $handle = fopen($path, 'r');
            $free = flock($handle, LOCK_EX | LOCK_NB);
            flock($handle, LOCK_UN);
            fclose($handle);
            if (! $free) {
                throw new ModMenuException(self::BUSY);
            }
        }
        return parent::exclusive($fn);
    }
}

class PrivateInstallNonBlockingSources extends SourceRepository
{
    protected function catalogLock(): CatalogLock
    {
        return new PrivateInstallNonBlockingCatalogLock(PrivateState::defaultDir($this->container));
    }
}

class PrivateInstallTest extends Base
{
    private const PUBLIC_URL = 'https://catalog.example/plugins.json';
    private const PRIVATE_URL = 'https://raw.githubusercontent.com/acme/dir/main/plugins.json';
    private const OTHER_URL = 'https://raw.githubusercontent.com/acme/dir/main/more.json';
    private const REF = 'vault://1/token';

    private string $root;
    private string $active;
    private string $disabled;
    private string $dir;
    private PrivateInstallFakeFetcher $fetcher;
    private PrivateInstallSpyManager $manager;
    private array $publicPlugins = [];
    private array $publicZips = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/modmenu-private-install-'.bin2hex(random_bytes(6));
        $this->active = $this->root.'/plugins';
        $this->disabled = $this->root.'/disabled';
        $this->dir = $this->root.'/modmenu';
        mkdir($this->active, 0700, true);
        mkdir($this->disabled, 0700, true);
        $this->container['modMenuPrivateStateDir'] = $this->dir;
        $this->fetcher = new PrivateInstallFakeFetcher();
        $this->container['modMenuPrivateFetcher'] = $this->fetcher;
        $this->manager = $this->newManager();
        $this->publicListing([]);
    }

    public function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    // ── bindings ───────────────────────────────────────────────────────────

    public function testInstallWritesBindingBeforeActivation(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $seen = null;
        $this->manager->atCommit = function (string $name) use (&$seen): void {
            $seen = $this->bindings();
            $this->assertDirectoryDoesNotExist($this->active.'/Secret');
            $this->assertLocksHeld($name);
        };

        $this->install('Secret');

        $this->assertSame(['secret' => $id], $seen);
        $this->assertSame(['secret' => $id], $this->bindings());
        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertSame([['id' => $id, 'url' => GithubUrl::source(self::PRIVATE_URL)['endpoint'], 'token_ref' => self::REF], $this->download('Secret', '1.0.0')], $this->fetcher->downloads[0]);
    }

    public function testActivationFailureKeepsBinding(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->manager->atCommit = static function (): void {
            throw new ModMenuException('activation failed');
        };

        $this->assertRefused('activation failed', fn () => $this->install('Secret'));

        $this->assertDirectoryDoesNotExist($this->active.'/Secret');
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testUninstallFailureKeepsBinding(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->manager->failRemoval = true;

        $this->assertRefused('Could not remove &quot;Secret&quot;. Its folder may be a bind mount or read-only.', fn () => $this->manager->uninstall('Secret'));

        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testUninstallRemovesBindingAfterFiles(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->state()->mutate(static function (array $doc) use ($id): array {
            $doc['seen'][$id][] = 'other';
            $doc['dismissed'][$id] = ['secret', 'other'];
            return $doc;
        });
        $during = null;
        $this->manager->atRemoval = function () use (&$during): void {
            $during = $this->bindings();
        };

        $this->manager->uninstall('Secret');

        $this->assertSame(['secret' => $id], $during);
        $this->assertDirectoryDoesNotExist($this->active.'/Secret');
        $this->assertSame([], $this->bindings());
        $this->assertSame([$id => ['other']], $this->state()->read()['dismissed']);
    }

    public function testPublicInstallWithoutPrivateStateWritesNothing(): void
    {
        $this->publicPlugin('Alpha', '1.0.0');
        $result = $this->fetch();

        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha');
        $this->manager->uninstall('Alpha');

        $this->assertFalse($this->state()->hasFile());
        $this->assertSame('', $this->storedMarker());
    }

    public function testFirstPrivateAddAtCommitWaitsForPublicInstall(): void
    {
        $this->publicPlugin('Alpha', '1.0.0');
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $added = null;
        $this->manager->atCommit = function () use (&$added): void {
            try {
                (new PrivateInstallNonBlockingSources($this->container))->addPrivateSource(self::PRIVATE_URL, self::REF, ['alpha']);
                $added = 'added';
            } catch (ModMenuException $e) {
                $added = $e->getMessage();
            }
        };

        $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha');

        $this->assertSame(PrivateInstallNonBlockingCatalogLock::BUSY, $added);
        $this->assertSame('1.0.0', $this->version('Alpha'));
        $this->assertFalse($this->state()->hasFile());
        $this->assertSame('', $this->storedMarker());
    }

    // ── freshness ──────────────────────────────────────────────────────────

    public function testNothingInstallsWhilePrivateSourceDown(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->publicPlugin('Alpha', '1.0.0');
        $this->fetcher->listings[$id] = new ModMenuException("Couldn't load this private source.");

        $result = $this->fetch();
        $this->assertFalse($result['private_ok']);
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));

        $this->assertRefused('Private sources must be reachable to change plugins', fn () => $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha'));
        $this->assertRefused('Private sources must be reachable to change plugins', fn () => $this->manager->installFromFile($this->publicZips['Alpha']));
        $this->assertDirectoryDoesNotExist($this->active.'/Alpha');
        $this->assertSame([], $this->fetcher->downloads);
    }

    public function testQuarantineRefusesEveryMutation(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->seed($this->active, 'Old', '1.0.0', ['deprecated' => ['superseded_by' => 'Secret']]);
        $result = $this->fetch();
        $result['quarantined'] = ['secret'];
        $result['private_ok'] = false;

        $this->assertEveryCatalogMutationRefused($result, $id, 'Private sources must be reachable to change plugins');
    }

    public function testFrozenRefusesEveryMutation(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->seed($this->active, 'Old', '1.0.0', ['deprecated' => ['superseded_by' => 'Secret']]);
        file_put_contents($this->dir.'/private-state.json', '{"broken"');
        $result = $this->fetch();
        $this->assertTrue($result['frozen']);

        $this->assertEveryCatalogMutationRefused($result, $id, "ModMenu's private source state is damaged");
        $this->assertRefused("ModMenu's private source state is damaged", fn () => $this->manager->uninstall('Secret'));
        $this->assertRefused("ModMenu's private source state is damaged", fn () => $this->manager->unbind('Secret'));
        $this->assertDirectoryExists($this->active.'/Secret');
    }

    public function testUnbindWorksWhileDown(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0', 'Other' => '1.0.0']);
        $this->install('Secret');
        $this->install('Other');
        $this->fetcher->listings[$id] = new ModMenuException("Couldn't load this private source.");

        $this->manager->unbind('Secret');
        $this->manager->uninstall('Other');

        $this->assertSame([], $this->bindings());
        $this->assertDirectoryExists($this->active.'/Secret');
        $this->assertDirectoryDoesNotExist($this->active.'/Other');
    }

    public function testGenerationChangeBetweenFetchAndActivationRefused(): void
    {
        $this->privateSource(['Secret' => '1.0.0']);
        $this->fetcher->during = function (): void {
            (new SourceRepository($this->container))->addSource('https://other.example/plugins.json');
        };

        $this->assertRefused('Sources changed; try again', fn () => $this->install('Secret'));

        $this->assertDirectoryDoesNotExist($this->active.'/Secret');
        $this->assertSame([], $this->bindings());
    }

    public function testResetBetweenFetchAndActivationRefused(): void
    {
        $this->privateSource(['Secret' => '1.0.0']);
        $this->fetcher->during = function (): void {
            (new CatalogLock($this->dir))->exclusive(fn () => $this->state()->reset());
        };

        $this->assertRefused('Sources changed; try again', fn () => $this->install('Secret'));

        $this->assertDirectoryDoesNotExist($this->active.'/Secret');
        $this->assertSame([], $this->bindings());
    }

    public function testStateFileAppearingAfterAPublicOnlyFetchRefuses(): void
    {
        $this->publicPlugin('Alpha', '1.0.0');
        $result = $this->fetch();
        $this->assertFalse($this->state()->hasFile());
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $this->addPrivate(self::PRIVATE_URL, []);

        $this->assertRefused('Sources changed; try again', fn () => $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha'));
        $this->assertDirectoryDoesNotExist($this->active.'/Alpha');
    }

    public function testCallWithoutAGuardRefusesOncePrivateStateExists(): void
    {
        $this->privateSource([]);
        $this->publicPlugin('Alpha', '1.0.0');
        $this->manager->setCatalog($this->fetch()['plugins']);

        $this->assertRefused('Sources changed; try again', fn () => $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha'));
    }

    // ── bound plugins ──────────────────────────────────────────────────────

    public function testBoundPluginNeverUsesPublicEntry(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->publicPlugin('Secret', '2.0.0');
        $publicUrl = $this->publicDownload('Secret', '2.0.0');

        // Listed zero times.
        $this->fetcher->listings[$id] = [];
        $this->assertBoundUnavailable($publicUrl);

        // Listed twice.
        $this->fetcher->listings[$id] = [$this->privateEntry('Secret', '3.0.0'), $this->privateEntry('SECRET', '3.0.0')];
        $this->assertBoundUnavailable($publicUrl);

        // A forged catalog: the public entry under the bound name.
        $result = $this->fetch();
        $catalog = $result['plugins'];
        $catalog['Secret'] = ['name' => 'Secret', 'version' => '2.0.0', 'download' => $publicUrl];
        $this->manager->setCatalog($catalog, new MutationGuard($this->container, $result));
        $this->assertRefused('Private source unavailable', fn () => $this->manager->installFromUrl($publicUrl, 'Secret'));

        // Source removed: its binding stays and blocks.
        (new SourceRepository($this->container))->removePrivateSource($id);
        $this->assertBoundUnavailable($publicUrl);

        // Source failed: nothing changes at all.
        $this->addPrivate(self::PRIVATE_URL, ['secret']);
        $this->fetcher->listings[$id] = new ModMenuException("Couldn't load this private source.");
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $this->assertRefused('Private sources must be reachable to change plugins', fn () => $this->manager->installFromUrl($publicUrl, 'Secret'));

        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertSame(['secret' => $id], $this->bindings());
        $this->assertCount(1, $this->fetcher->downloads);
    }

    public function testBoundUpdateComesFromItsSource(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->fetcher->listings[$id] = [$this->privateEntry('Secret', '2.0.0')];
        $result = $this->fetch();
        $held = 0;
        $this->manager->atCommit = function (string $name) use (&$held): void {
            $this->assertLocksHeld($name);
            $held++;
        };

        $this->assertSame(['updated' => ['Secret'], 'failed' => []], $this->manager->updateAll($result['plugins'], new MutationGuard($this->container, $result)));

        $this->assertSame('2.0.0', $this->version('Secret'));
        $this->assertSame(['secret' => $id], $this->bindings());
        $this->assertSame(1, $held);
    }

    public function testDigestMismatchRefusesAndLeavesBindingUnchanged(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->fetcher->listings[$id] = [array_replace($this->privateEntry('Secret', '2.0.0'), ['sha256' => str_repeat('b', 64)])];

        $this->assertRefused('The downloaded archive does not match the checksum in the directory. Nothing was installed.', fn () => $this->install('Secret'));

        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testPrivateEntryWithoutChecksumRefused(): void
    {
        $this->privateSource(['Secret' => '1.0.0']);
        $result = $this->fetch();
        $catalog = $result['plugins'];
        unset($catalog['Secret']['sha256']);
        $this->manager->setCatalog($catalog, new MutationGuard($this->container, $result));

        $this->assertRefused('The directory lists an invalid checksum for this plugin. Nothing was installed.', fn () => $this->manager->installFromUrl($catalog['Secret']['download'], 'Secret'));
        $this->assertSame([], $this->fetcher->downloads);
    }

    // ── seen names, adopt, dismiss ─────────────────────────────────────────

    public function testSeenUnboundNotUpdatedFromPublic(): void
    {
        $this->seed($this->active, 'Alpha', '1.0.0');
        $this->privateSource([], ['alpha']);
        $this->publicPlugin('Alpha', '2.0.0');
        $result = $this->fetch();

        $this->assertSame([], $this->manager->updatesAvailable($result['plugins']));
        $this->assertSame(
            ['updated' => [], 'failed' => ['Alpha' => 'Listed by a private source: adopt or dismiss']],
            $this->manager->updateAll($result['plugins'], new MutationGuard($this->container, $result))
        );
        $this->assertRefused('Listed by a private source: adopt or dismiss', fn () => $this->manager->installFromUrl($this->publicDownload('Alpha', '2.0.0'), 'Alpha'));
        $this->assertSame('1.0.0', $this->version('Alpha'));
    }

    // ── one identity per key on the download path (MM-IMPL-R2-01) ──────────

    // R17: key lookup only finds the entry; the entry's own name must equal the folder byte for byte.

    public function testMixedCaseDependencyUpdateIsRefusedUnderAnotherSpelling(): void
    {
        [$id, $plan, $mine] = $this->boundSecretUnderAnotherSpelling(fn (string $zip) => hash_file('sha256', $zip));
        $downloads = count($this->fetcher->downloads);

        $this->assertRefused('The directory lists this plugin as &quot;SECRET&quot;, not &quot;Secret&quot;. Nothing was installed.', fn () => $this->manager->resolveAndActivate('Mine', 'install', $mine, $plan));

        $this->assertCount($downloads, $this->fetcher->downloads, 'refused before any download');
        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertNull($this->version('Mine'));
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testCatalogEntryUnderAnotherSpellingIsNeverInstalled(): void
    {
        $id = $this->privateSource([]);
        $zip = $this->zip('Secret', '1.0.0');
        $this->fetcher->releases[$this->download('SECRET', '1.0.0')] = $zip;
        $this->fetcher->listings[$id] = [[
            'name' => 'SECRET', 'version' => '1.0.0', 'sha256' => hash_file('sha256', $zip), 'download' => $this->download('SECRET', '1.0.0'),
            'requires' => [['plugin' => 'Helper']], 'conflicts' => ['Rival'],
        ]];
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));

        $this->assertRefused('The directory lists this plugin as &quot;SECRET&quot;, not &quot;Secret&quot;. Nothing was installed.', fn () => $this->manager->installFromUrl($this->download('SECRET', '1.0.0'), 'Secret'));
        $this->assertSame([], $this->fetcher->downloads);
        $this->assertRefused('The archive for &quot;SECRET&quot; contains &quot;Secret&quot;. Nothing was installed.', fn () => $this->manager->installFromUrl($this->download('SECRET', '1.0.0'), 'SECRET'));

        $this->assertNull($this->version('Secret'));
        $this->assertSame([], $this->bindings());
    }

    public function testMixedCaseListingNeverUpdatesASeenUnboundPluginFromThePublicPath(): void
    {
        $this->seed($this->active, 'RPCGUARD', '1.0.0');
        $this->privateSource(['RpcGuard' => '2.0.0'], ['rpcguard']);
        $public = 0;
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->method('download')->willReturnCallback(function () use (&$public): string {
            $public++;
            $path = tempnam($this->root, 'download-');
            copy($this->zip('RPCGUARD', '2.0.0'), $path);
            return $path;
        });
        $this->manager->setArchiveDownloader($downloader);
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));

        // R17 refuses the case variant first; with the same spelling the seen-unbound gate refuses.
        $this->assertRefused('The directory lists this plugin as &quot;RpcGuard&quot;, not &quot;RPCGUARD&quot;. Nothing was installed.', fn () => $this->manager->installFromUrl($this->download('RpcGuard', '2.0.0'), 'RPCGUARD'));

        $this->assertSame(0, $public);
        $this->assertSame([], $this->fetcher->downloads);
        $this->assertSame('1.0.0', $this->version('RPCGUARD'));
        $this->assertSame([], $this->bindings());
    }

    public function testUploadNeverUpdatesASeenUnboundPlugin(): void
    {
        $this->seed($this->active, 'Alpha', '1.0.0');
        $this->privateSource([], ['alpha']);
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));

        $this->assertRefused('Listed by a private source: adopt or dismiss', fn () => $this->manager->installFromFile($this->zip('Alpha', '2.0.0')));

        $this->assertSame('1.0.0', $this->version('Alpha'));
    }

    public function testUnboundPluginIsNotBoundByAPrivateUpdate(): void
    {
        $this->seed($this->active, 'Secret', '0.9.0');
        $this->privateSource(['Secret' => '1.0.0']);

        $this->assertRefused('Listed by a private source: adopt or dismiss', fn () => $this->install('Secret'));

        $this->assertSame('0.9.0', $this->version('Secret'));
        $this->assertSame([], $this->bindings());
    }

    public function testDismissedIsUpdatedFromPublicAndSurvivesNextFetch(): void
    {
        $this->seed($this->active, 'Alpha', '1.0.0');
        $id = $this->privateSource([], ['alpha']);
        $this->publicPlugin('Alpha', '2.0.0');

        $this->manager->dismiss('Alpha', $id, $this->fetch());
        $result = $this->fetch();

        $this->assertSame(['alpha'], $this->state()->read()['seen'][$id]);
        $this->assertSame([$id => ['alpha']], $this->state()->read()['dismissed']);
        $this->assertSame(['Alpha'], array_column($this->manager->updatesAvailable($result['plugins']), 'plugin'));
        $this->assertSame(['updated' => ['Alpha'], 'failed' => []], $this->manager->updateAll($result['plugins'], new MutationGuard($this->container, $result)));
        $this->assertSame('2.0.0', $this->version('Alpha'));
    }

    public function testDismissNeedsAnInstalledUnboundPlugin(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0'], ['alpha']);
        $this->install('Secret');
        $result = $this->fetch();

        $this->assertRefused('Plugin &quot;Alpha&quot; is not installed.', fn () => $this->manager->dismiss('Alpha', $id, $result));
        $this->assertRefused('Secret is already bound to a private source', fn () => $this->manager->dismiss('Secret', $id, $result));
        $this->assertSame([], $this->state()->read()['dismissed']);
    }

    public function testAdoptOnlyOnRequest(): void
    {
        $this->seed($this->active, 'Secret', '1.0.0');
        $id = $this->privateSource(['Secret' => '2.0.0']);
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            static fn ($url, $address, $header, $write) => [500, null]
        );

        $result = $this->fetch();
        $this->assertNotSame([], $result['errors']);
        $this->manager->updateAll($result['plugins'], new MutationGuard($this->container, $result));

        $this->assertSame([], $this->bindings());
        $this->assertSame('1.0.0', $this->version('Secret'));

        $this->manager->adopt('Secret', $id, $result);
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testAdoptRefusesWhenNotListedThisRequest(): void
    {
        $this->seed($this->active, 'Secret', '1.0.0');
        $id = $this->privateSource([], ['secret']);
        $result = $this->fetch();

        $this->assertRefused('Secret is not listed by this private source', fn () => $this->manager->adopt('Secret', $id, $result));
        $this->assertRefused('Private source unavailable', fn () => $this->manager->adopt('Secret', str_repeat('c', 64), $result));
        $this->assertSame([], $this->bindings());
    }

    public function testAdoptAllowedWhenAlsoPublic(): void
    {
        $this->seed($this->active, 'Secret', '1.0.0');
        $id = $this->privateSource(['Secret' => '2.0.0']);
        $this->publicPlugin('Secret', '3.0.0');
        $result = $this->fetch();
        $this->assertArrayNotHasKey('Secret', $result['plugins']);
        $this->state()->mutate(static function (array $doc) use ($id): array {
            $doc['dismissed'][$id] = ['secret'];
            return $doc;
        });

        $this->manager->adopt('Secret', $id, $result);

        $this->assertSame(['secret' => $id], $this->bindings());
        $this->assertSame([], $this->state()->read()['dismissed']);
        $this->assertRefused('Secret is already bound to a private source', fn () => $this->manager->adopt('Secret', $id, $result));
        $next = $this->fetch();
        $this->assertSame($id, $next['plugins']['Secret']['source_id']);
    }

    // ── dependencies and plans ─────────────────────────────────────────────

    public function testPrivateDependencyFromPublicSourceUnresolved(): void
    {
        $id = $this->privateSource([]);
        $this->fetcher->listings[$id] = [$this->privateEntry('Secret', '1.0.0', ['requires' => [['plugin' => 'Helper']]])];
        $this->publicPlugin('Helper', '1.0.0');
        $catalog = $this->fetch()['plugins'];

        $check = $this->manager->forwardCheck(PluginManager::normalizeDeps($catalog['Secret']['requires']), $catalog, null, $id);

        $this->assertTrue($check['blocked']);
        $this->assertSame([['plugin' => 'Helper', 'action' => 'unresolvable', 'download' => null, 'min_version' => null, 'reason' => 'Install it yourself first']], $check['plan']);
    }

    public function testInstalledPluginSatisfiesPrivateDependency(): void
    {
        $id = $this->privateSource([]);
        $this->seed($this->active, 'Helper', '1.0.0');
        $this->fetcher->listings[$id] = [$this->privateEntry('Secret', '1.0.0', ['requires' => [['plugin' => 'Helper']]])];
        $catalog = $this->fetch()['plugins'];

        $check = $this->manager->forwardCheck(PluginManager::normalizeDeps($catalog['Secret']['requires']), $catalog, null, $id);

        $this->assertTrue($check['satisfied']);
        $this->assertSame([], $check['plan']);
    }

    public function testCallerPlanInstallingAPublicDependencyForAPrivateDependantRefused(): void
    {
        $id = $this->privateSource([]);
        $this->fetcher->listings[$id] = [$this->privateEntry('Secret', '1.0.0', ['requires' => [['plugin' => 'Helper']]])];
        $this->publicPlugin('Helper', '1.0.0');
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $forged = [['plugin' => 'Helper', 'action' => 'install', 'download' => $this->publicDownload('Helper', '1.0.0'), 'min_version' => null]];

        $this->assertRefused(PluginManager::MIGRATION_PLAN_CHANGED_MESSAGE, fn () => $this->manager->resolveAndActivate('Secret', 'install', $result['plugins']['Secret']['download'], $forged));

        $this->assertDirectoryDoesNotExist($this->active.'/Helper');
        $this->assertDirectoryDoesNotExist($this->active.'/Secret');
        $this->assertSame([], $this->fetcher->downloads);
    }

    public function testBoundPluginEnableGetsThePrivateDependencyRule(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->manager->disable('Secret');
        file_put_contents($this->disabled.'/Secret/plugin.json', json_encode(['name' => 'Secret', 'version' => '1.0.0', 'requires' => [['plugin' => 'Helper']]]));
        $this->fetcher->listings[$id] = [];
        $this->publicPlugin('Helper', '1.0.0');
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $forged = [['plugin' => 'Helper', 'action' => 'install', 'download' => $this->publicDownload('Helper', '1.0.0'), 'min_version' => null]];

        $this->assertRefused(PluginManager::MIGRATION_PLAN_CHANGED_MESSAGE, fn () => $this->manager->resolveAndActivate('Secret', 'enable', '', $forged));

        $this->assertDirectoryDoesNotExist($this->active.'/Helper');
        $this->assertDirectoryExists($this->disabled.'/Secret');
    }

    public function testBindingDecidesTheRootDependencySource(): void
    {
        $a = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->manager->disable('Secret');
        file_put_contents($this->disabled.'/Secret/plugin.json', json_encode(['name' => 'Secret', 'version' => '1.0.0', 'requires' => [['plugin' => 'Helper']]]));
        $b = $this->addPrivate(self::OTHER_URL, []);
        $this->fetcher->listings[$b] = [$this->privateEntry('Helper', '1.0.0')];
        $result = $this->fetch();
        $catalog = $result['plugins'];
        $this->assertSame($b, $catalog['Helper']['source_id']);
        $catalog['Secret']['source_id'] = $b;
        $this->manager->setCatalog($catalog, new MutationGuard($this->container, $result));
        $plan = [['plugin' => 'Helper', 'action' => 'install', 'download' => $catalog['Helper']['download'], 'min_version' => null]];

        $this->assertRefused(PluginManager::MIGRATION_PLAN_CHANGED_MESSAGE, fn () => $this->manager->resolveAndActivate('Secret', 'enable', '', $plan));

        $this->assertDirectoryDoesNotExist($this->active.'/Helper');
        $this->assertDirectoryExists($this->disabled.'/Secret');
        $this->assertSame(['secret' => $a], $this->bindings());
        $this->assertCount(1, $this->fetcher->downloads);
    }

    public function testEnabledBoundDependencyGetsItsRequirementsFromItsSource(): void
    {
        $this->privateSource(['Inner' => '1.0.0']);
        $this->install('Inner');
        $this->manager->disable('Inner');
        file_put_contents($this->disabled.'/Inner/plugin.json', json_encode(['name' => 'Inner', 'version' => '1.0.0', 'requires' => [['plugin' => 'Helper']]]));
        $this->publicPlugin('Helper', '1.0.0');
        $this->publicPlugin('Pub', '1.0.0');
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $plan = [
            ['plugin' => 'Helper', 'action' => 'install', 'download' => $this->publicDownload('Helper', '1.0.0'), 'min_version' => null],
            ['plugin' => 'Inner', 'action' => 'enable', 'download' => null, 'min_version' => null],
        ];

        $this->assertRefused(PluginManager::MIGRATION_PLAN_CHANGED_MESSAGE, fn () => $this->manager->resolveAndActivate('Pub', 'install', $this->publicDownload('Pub', '1.0.0'), $plan));

        $this->assertDirectoryDoesNotExist($this->active.'/Helper');
        $this->assertDirectoryDoesNotExist($this->active.'/Pub');
        $this->assertDirectoryExists($this->disabled.'/Inner');
    }

    public function testNestedPrivateDependencyInACallerPlanRefused(): void
    {
        $id = $this->privateSource([]);
        $this->fetcher->listings[$id] = [$this->privateEntry('Mine', '1.0.0', ['requires' => [['plugin' => 'Helper']]])];
        $this->publicPlugin('Helper', '1.0.0');
        $this->publicPlugin('Pub', '1.0.0');
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $forged = [
            ['plugin' => 'Helper', 'action' => 'install', 'download' => $this->publicDownload('Helper', '1.0.0'), 'min_version' => null],
            ['plugin' => 'Mine', 'action' => 'install', 'download' => $result['plugins']['Mine']['download'], 'min_version' => null],
        ];

        $this->assertRefused(PluginManager::MIGRATION_PLAN_CHANGED_MESSAGE, fn () => $this->manager->resolveAndActivate('Pub', 'install', $this->publicDownload('Pub', '1.0.0'), $forged));

        $this->assertDirectoryDoesNotExist($this->active.'/Helper');
        $this->assertDirectoryDoesNotExist($this->active.'/Mine');
    }

    public function testMixedCaseNestedDependencyInACallerPlanRefused(): void
    {
        $id = $this->privateSource([]);
        $this->fetcher->listings[$id] = [$this->privateEntry('Mine', '1.0.0', ['requires' => [['plugin' => 'helper']]])];
        $this->publicPlugin('Helper', '1.0.0');
        $this->publicPlugin('Pub', '1.0.0');
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $forged = [
            ['plugin' => 'Helper', 'action' => 'install', 'download' => $this->publicDownload('Helper', '1.0.0'), 'min_version' => null],
            ['plugin' => 'Mine', 'action' => 'install', 'download' => $result['plugins']['Mine']['download'], 'min_version' => null],
        ];

        $this->assertRefused(PluginManager::MIGRATION_PLAN_CHANGED_MESSAGE, fn () => $this->manager->resolveAndActivate('Pub', 'install', $this->publicDownload('Pub', '1.0.0'), $forged));

        $this->assertDirectoryDoesNotExist($this->active.'/Helper');
        $this->assertDirectoryDoesNotExist($this->active.'/Mine');
    }

    public function testPrivateEntryWithoutValidSourceIdFailsClosed(): void
    {
        $this->publicPlugin('Alpha', '1.0.0');
        $result = $this->fetch();
        $catalog = $result['plugins'];
        $catalog['Alpha']['private'] = true;
        $this->manager->setCatalog($catalog, new MutationGuard($this->container, $result));

        $this->assertRefused('Private source unavailable', fn () => $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha'));
        $catalog['Alpha']['source_id'] = 'not-an-id';
        $this->manager->setCatalog($catalog, new MutationGuard($this->container, $result));
        $this->assertRefused('Private source unavailable', fn () => $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha'));
        $this->assertDirectoryDoesNotExist($this->active.'/Alpha');
    }

    public function testChangedSourceIdBetweenConfirmAndRunRefusesPlan(): void
    {
        $this->seed($this->active, 'Old', '1.0.0', ['deprecated' => ['superseded_by' => 'Secret']]);
        $a = $this->privateEntry('Secret', '1.0.0') + ['private' => true, 'source_id' => str_repeat('a', 64)];
        $b = array_replace($a, ['source_id' => str_repeat('b', 64)]);
        $public = array_diff_key($a, ['private' => true, 'source_id' => true]);
        $step = [['plugin' => 'Helper', 'action' => 'install', 'download' => 'https://x/helper.zip']];
        $helperA = ['private' => true, 'source_id' => str_repeat('a', 64)];

        $digest = $this->manager->planDigest([], 'Secret', ['Secret' => $a]);
        $this->assertNotSame($digest, $this->manager->planDigest([], 'Secret', ['Secret' => $b]));
        $this->assertNotSame($digest, $this->manager->planDigest([], 'Secret', ['Secret' => $public]));
        $this->assertNotSame(
            $this->manager->planDigest($step, 'Secret', ['Secret' => $a, 'Helper' => $helperA]),
            $this->manager->planDigest($step, 'Secret', ['Secret' => $a, 'Helper' => ['source_id' => str_repeat('b', 64)] + $helperA])
        );

        $confirmed = $this->manager->migrationCheck('Old', 'Secret', ['Secret' => $a])['plan_digest'];
        try {
            $this->manager->migrate('Old', ['Secret' => $b], true, $confirmed);
            $this->fail('expected the changed plan to be refused');
        } catch (ModMenuException $e) {
            $this->assertSame(PluginManager::MIGRATION_PLAN_CHANGED, $e->getCode());
        }
        $this->assertSame([], $this->fetcher->downloads);
        $this->assertDirectoryExists($this->active.'/Old');
    }

    public function testPrivateMigrationInstallsFromItsSourceAndBinds(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->seed($this->active, 'Old', '1.0.0', ['deprecated' => ['superseded_by' => 'Secret']]);
        $result = $this->fetch();
        $digest = $this->manager->migrationCheck('Old', 'Secret', $result['plugins'])['plan_digest'];
        $held = [];
        $this->manager->atCommit = function (string $name) use (&$held): void {
            $this->assertLocksHeld($name);
            $this->assertLocksHeld('Old');
            $held[] = $name;
        };

        $this->assertSame('Secret', $this->manager->migrate('Old', $result['plugins'], false, $digest, new MutationGuard($this->container, $result)));

        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertDirectoryExists($this->disabled.'/Old');
        $this->assertSame(['secret' => $id], $this->bindings());
        $this->assertSame(['Secret'], $held);
    }

    public function testTwoInstalledFoldersOneKeyFailClosed(): void
    {
        $this->seed($this->active, 'Secret', '0.9.0');
        $this->seed($this->disabled, 'secret', '0.8.0');
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->publicPlugin('Alpha', '1.0.0');
        $result = $this->fetch();

        $this->assertSame([], $this->manager->updatesAvailable($result['plugins']));
        $message = 'Two installed folders share the name Secret; remove one first';
        $this->assertRefused($message, fn () => $this->install('Secret'));
        $this->assertRefused($message, fn () => $this->manager->adopt('Secret', $id, $result));
        $this->assertSame([], $this->bindings());
        $this->assertSame('0.9.0', $this->version('Secret'));
    }

    // ── interleavings (the competitor finds the plugin busy or the guard refuses) ──

    public function testInstallVersusUnbind(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $busy = $this->competeAtCommit(fn () => $this->newManager()->unbind('Secret'));

        $this->install('Secret');

        $this->assertSame('Secret is busy with another ModMenu action. Try again in a moment.', $busy());
        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testUpdateVersusUnbind(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->fetcher->listings[$id] = [$this->privateEntry('Secret', '2.0.0')];
        $busy = $this->competeAtCommit(fn () => $this->newManager()->unbind('Secret'));

        $this->install('Secret');

        $this->assertSame('Secret is busy with another ModMenu action. Try again in a moment.', $busy());
        $this->assertSame('2.0.0', $this->version('Secret'));
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testInstallVersusUninstall(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $busy = $this->competeAtCommit(fn () => $this->newManager()->uninstall('Secret'));

        $this->install('Secret');

        $this->assertSame('Secret is busy with another ModMenu action. Try again in a moment.', $busy());
        $this->assertDirectoryExists($this->active.'/Secret');
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testAdoptVersusUpdate(): void
    {
        $this->seed($this->active, 'Secret', '1.0.0');
        $id = $this->privateSource(['Secret' => '2.0.0']);
        $result = $this->fetch();
        $updated = null;
        $this->manager->atCommit = function () use ($result, &$updated): void {
            $updated = $this->newManager()->updateAll($result['plugins'], new MutationGuard($this->container, $result));
        };

        $this->manager->adopt('Secret', $id, $result);

        $this->assertSame(['updated' => [], 'failed' => ['Secret' => 'Listed by a private source: adopt or dismiss']], $updated);
        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertSame(['secret' => $id], $this->bindings());
    }

    public function testPrivateAddVersusPublicInstall(): void
    {
        $this->publicPlugin('Alpha', '1.0.0');
        $result = $this->fetch();
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->method('download')->willReturnCallback(function (): string {
            $this->addPrivate(self::PRIVATE_URL, ['alpha']);
            $path = tempnam($this->root, 'download-');
            copy($this->publicZips['Alpha'], $path);
            return $path;
        });
        $this->manager->setArchiveDownloader($downloader);
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));

        $this->assertRefused('Sources changed; try again', fn () => $this->manager->installFromUrl($this->publicDownload('Alpha', '1.0.0'), 'Alpha'));

        $this->assertDirectoryDoesNotExist($this->active.'/Alpha');
        $this->assertSame([], $this->bindings());
    }

    public function testPrivateRemoveVersusBoundUpdate(): void
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $this->fetcher->listings[$id] = [$this->privateEntry('Secret', '2.0.0')];
        $this->fetcher->during = function () use ($id): void {
            (new SourceRepository($this->container))->removePrivateSource($id);
        };

        $this->assertRefused('Sources changed; try again', fn () => $this->install('Secret'));

        $this->assertSame('1.0.0', $this->version('Secret'));
        $this->assertSame(['secret' => $id], $this->bindings());
        $this->assertArrayHasKey($id, $this->state()->read()['removed']);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function assertEveryCatalogMutationRefused(array $result, string $id, string $message): void
    {
        $guard = fn () => new MutationGuard($this->container, $result);
        $catalog = $result['plugins'];
        $this->manager->setCatalog($catalog, $guard());
        $download = $this->download('Secret', '1.0.0');
        $downloads = count($this->fetcher->downloads);

        $this->assertRefused($message, fn () => $this->manager->installFromUrl($download, 'Secret'));
        $this->assertRefused($message, fn () => $this->manager->installFromFile($this->zip('Secret', '1.0.0')));
        $this->assertRefused($message, fn () => $this->manager->updateAll($catalog, $guard()));
        $this->assertRefused($message, fn () => $this->manager->migrate('Old', $catalog, true, null, $guard()));
        $this->assertRefused($message, fn () => $this->manager->adopt('Secret', $id, $result));
        $this->assertRefused($message, fn () => $this->manager->dismiss('Secret', $id, $result));
        $this->assertDirectoryExists($this->active.'/Old');
        $this->assertCount($downloads, $this->fetcher->downloads);
    }

    private function assertBoundUnavailable(string $publicUrl): void
    {
        $result = $this->fetch();
        $this->assertArrayNotHasKey('Secret', $result['plugins']);
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $this->assertRefused('Private source unavailable', fn () => $this->manager->installFromUrl($publicUrl, 'Secret'));
        $this->assertSame([], $this->manager->updatesAvailable($result['plugins']));
        $this->assertSame(
            ['updated' => [], 'failed' => ['Secret' => 'Private source unavailable']],
            $this->manager->updateAll($result['plugins'], new MutationGuard($this->container, $result))
        );
    }

    /**
     * Secret 1.0.0 installed and bound; its source now lists it as SECRET
     * 2.0.0 (the archive's folder is Secret) and Mine, which requires
     * secret >= 2.0.0. Returns [source id, Mine's dependency plan, Mine's download].
     *
     * @param callable(string): string $sha256 the listed digest for the SECRET archive
     */
    private function boundSecretUnderAnotherSpelling(callable $sha256): array
    {
        $id = $this->privateSource(['Secret' => '1.0.0']);
        $this->install('Secret');
        $zip = $this->zip('Secret', '2.0.0');
        $this->fetcher->releases[$this->download('SECRET', '2.0.0')] = $zip;
        $requires = [['plugin' => 'secret', 'min_version' => '2.0.0']];
        $this->fetcher->listings[$id] = [
            ['name' => 'SECRET', 'version' => '2.0.0', 'sha256' => $sha256($zip), 'download' => $this->download('SECRET', '2.0.0')],
            $this->privateEntry('Mine', '1.0.0', ['requires' => $requires]),
        ];
        $result = $this->fetch();
        $this->assertArrayHasKey('SECRET', $result['plugins']);
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $plan = (new DependencyResolver($this->container))->resolveClosure($requires, $this->manager->installedMap(), $result['plugins'], $id);
        $this->assertSame([['plugin' => 'Secret', 'action' => 'update', 'download' => $this->download('SECRET', '2.0.0'), 'min_version' => '2.0.0']], $plan);

        return [$id, $plan, $result['plugins']['Mine']['download']];
    }

    /** Runs $competitor at the manager's critical point; returns a getter for its refusal. */
    private function competeAtCommit(callable $competitor): Closure
    {
        $refusal = null;
        $this->manager->atCommit = function (string $name) use ($competitor, &$refusal): void {
            $this->assertLocksHeld($name);
            try {
                $competitor();
                $refusal = 'not refused';
            } catch (ModMenuException $e) {
                $refusal = $e->getMessage();
            }
        };
        return static function () use (&$refusal) {
            return $refusal;
        };
    }

    /** From a fresh handle: the catalog lock is held (shared) and so is $name's plugin lock. */
    private function assertLocksHeld(string $name): void
    {
        foreach ([$this->dir.'/'.CatalogLock::FILE, $this->root.'/.modmenu-install-'.hash('sha256', strtolower($name)).'.lock'] as $path) {
            $this->assertFileExists($path);
            $handle = fopen($path, 'r');
            $this->assertFalse(flock($handle, LOCK_EX | LOCK_NB, $wouldBlock), $path.' is not held');
            $this->assertSame(1, $wouldBlock);
            fclose($handle);
        }
    }

    private function assertRefused(string $message, callable $fn): void
    {
        try {
            $fn();
        } catch (ModMenuException $e) {
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail('Expected refusal: '.$message);
    }

    /** Fetch, then install $name from the fetched catalog with its guard. */
    private function install(string $name): void
    {
        $result = $this->fetch();
        $this->manager->setCatalog($result['plugins'], new MutationGuard($this->container, $result));
        $this->manager->installFromUrl((string) ($result['plugins'][$name]['download'] ?? 'https://x/none.zip'), $name);
    }

    private function fetch(): array
    {
        return (new DirectoryClient($this->container))->catalogResult();
    }

    private function newManager(): PrivateInstallSpyManager
    {
        $manager = new PrivateInstallSpyManager($this->container);
        $manager->setDirectories($this->active, $this->disabled);
        return $manager;
    }

    /**
     * Adds the private source listing $plugins (name => version) with
     * releases ready to download; $seen adds earlier seen names.
     */
    private function privateSource(array $plugins, array $seen = []): string
    {
        $id = $this->addPrivate(self::PRIVATE_URL, $seen);
        $this->fetcher->listings[$id] = [];
        foreach ($plugins as $name => $version) {
            $this->fetcher->listings[$id][] = $this->privateEntry($name, $version);
        }
        return $id;
    }

    private function addPrivate(string $url, array $seen): string
    {
        (new SourceRepository($this->container))->addPrivateSource($url, self::REF, $seen);
        return GithubUrl::source($url)['id'];
    }

    private function privateEntry(string $name, string $version, array $extra = []): array
    {
        $download = $this->download($name, $version);
        $zip = $this->zip($name, $version);
        $this->fetcher->releases[$download] = $zip;
        return array_replace([
            'name' => $name,
            'version' => $version,
            'sha256' => hash_file('sha256', $zip),
            'download' => $download,
        ], $extra);
    }

    private function download(string $name, string $version): string
    {
        return 'https://github.com/acme/'.strtolower($name).'/releases/download/v'.$version.'/'.$name.'-'.$version.'.zip';
    }

    private function publicDownload(string $name, string $version): string
    {
        return 'https://public.example/'.$name.'-'.$version.'.zip';
    }

    private function publicPlugin(string $name, string $version): void
    {
        $this->publicZips[$name] = $this->zip($name, $version);
        $this->publicPlugins[] = ['name' => $name, 'version' => $version, 'download' => $this->publicDownload($name, $version)];
        $this->publicListing($this->publicPlugins);
        $zips = [];
        foreach ($this->publicPlugins as $p) {
            $zips[$p['download']] = $this->zip($p['name'], $p['version']);
        }
        $downloader = $this->createMock(ArchiveDownloader::class);
        $downloader->method('download')->willReturnCallback(function (string $url) use ($zips): string {
            if (empty($zips[$url])) {
                throw new ModMenuException('Unable to download the plugin archive.');
            }
            $path = tempnam($this->root, 'download-');
            copy($zips[$url], $path);
            return $path;
        });
        $this->manager->setArchiveDownloader($downloader);
    }

    private function publicListing(array $plugins): void
    {
        $this->container['configModel']->save([SourceRepository::CONFIG_KEY => json_encode([self::PUBLIC_URL])]);
        $this->container['memoryCache']->flush();
        $this->container['modMenuDirectoryDownloader'] = new ArchiveDownloader(
            static fn () => ['140.82.112.3'],
            static function ($url, $address, $header, $write) use ($plugins) {
                $write(null, json_encode($plugins));
                return [200, null];
            }
        );
    }

    private function zip(string $name, string $version): string
    {
        $path = $this->root.'/'.$name.'-'.$version.'.zip';
        if (! is_file($path)) {
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString("$name/Plugin.php", "<?php\nnamespace Kanboard\\Plugin\\$name;\n");
            $zip->addFromString("$name/plugin.json", json_encode(['name' => $name, 'version' => $version]));
            $zip->close();
        }
        return $path;
    }

    private function seed(string $dir, string $name, string $version, array $metadata = []): void
    {
        mkdir("$dir/$name", 0700, true);
        file_put_contents("$dir/$name/Plugin.php", "<?php\nnamespace Kanboard\\Plugin\\$name;\n");
        file_put_contents("$dir/$name/plugin.json", json_encode(['name' => $name, 'version' => $version] + $metadata));
    }

    private function version(string $name): ?string
    {
        foreach ([$this->active, $this->disabled] as $dir) {
            $file = "$dir/$name/plugin.json";
            if (is_file($file)) {
                return json_decode((string) file_get_contents($file), true)['version'] ?? null;
            }
        }
        return null;
    }

    /** The marker as stored in the settings table (no request cache). */
    private function storedMarker(): string
    {
        return (string) $this->container['configModel']->getOption(PrivateState::MARKER, '');
    }

    private function bindings(): array
    {
        return $this->state()->read()['bindings'];
    }

    private function state(): PrivateState
    {
        return new PrivateState($this->container, $this->dir);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        chmod($path, 0700);
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }
        rmdir($path);
    }
}
