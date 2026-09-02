<?php

namespace Cromero\Composer\PatchesCommitLock;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\EventDispatcher\EventSubscriberInterface;
use cweagans\Composer\PatchEvent;
use cweagans\Composer\PatchEvents;
use cweagans\Composer\Patches as BasePatchesPlugin;
use Cromero\Composer\PatchesCommitLock\Capability\CommandProvider;
use Cromero\Composer\PatchesCommitLock\GitProvider\GitProviderRegistry;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    protected Composer $composer;
    protected IOInterface $io;
    protected array $commitHashes = [];
    protected GitProviderRegistry $providerRegistry;
    protected ?BasePatchesPlugin $patchesPlugin = null;
    protected array $localPatchCache = []; // originalUrl -> localPath
    protected array $localPatchInfo = []; // localPath -> commit info (commit, provider, originalUrl, description, package)

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        
        $this->providerRegistry = new GitProviderRegistry($io);
        $this->findPatchesPlugin();
        
        $this->io->write('<info>Git Patch Commit Lock plugin activated</info>', true, IOInterface::VERBOSE);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Auto-lock missing commits at the start of install/update
            'pre-install-cmd' => 'autoLockMissingCommits',
            'pre-update-cmd' => 'autoLockMissingCommits',
            // Priority -10 = runs AFTER gatherPatches (priority 0), before package install
            'pre-package-install' => ['downloadPatchesForPackage', -10],
            'pre-package-update' => ['downloadPatchesForPackage', -10],
            // Priority 11 = runs BEFORE Patches::postInstall (priority 10)
            'post-package-install' => ['rewritePatchUrlsToLocal', 11],
            'post-package-update' => ['rewritePatchUrlsToLocal', 11],
            'pre-patch-apply' => 'onPrePatchApply',
            'post-patch-apply' => 'onPostPatchApply',
        ];
    }

    // Auto-lock missing commits at the start of composer install/update
    public function autoLockMissingCommits(\Composer\Script\Event $event): void
    {
        $io = $this->io;
        $composer = $this->composer;
        
        $patches = $this->getPatchesFromRootPackage();
        if (empty($patches)) {
            return;
        }
        
        $lockFilePath = $composer->getConfig()->get('lock-file') ?? 'composer.lock';
        $existingCommits = $this->loadCommitLockFromComposerLock($lockFilePath);
        
        // Index existing commits by URL for quick lookup
        $existingByurl = [];
        foreach ($existingCommits as $entry) {
            $existingByurl[$entry['url']] = $entry;
        }
        
        $missingPatches = [];
        foreach ($patches as $package => $patchList) {
            foreach ($patchList as $description => $url) {
                if (!is_string($url)) {
                    continue;
                }
                if (!str_starts_with($url, 'https://git.drupalcode.org')) {
                    continue;
                }
                if (isset($existingByurl[$url])) {
                    continue;
                }
                $missingPatches[] = [
                    'package' => $package,
                    'description' => $description,
                    'url' => $url,
                ];
            }
        }
        
        if (empty($missingPatches)) {
            $io->write('<info>Patch commit lock: all git.drupalcode.org patches are locked</info>', true, IOInterface::VERBOSE);
            return;
        }
        
        $io->write('<info>Patch commit lock: ' . count($missingPatches) . ' patch(es) missing from lock, fetching...</info>');
        
        $newEntries = [];
        foreach ($missingPatches as $patch) {
            $provider = $this->providerRegistry->findProviderForUrl($patch['url']);
            if (!$provider) {
                continue;
            }
            
            $commitHash = $provider->fetchCommitHash($patch['url'], $io);
            if (!$commitHash) {
                $io->writeError("  <warning>Could not fetch commit for: {$patch['url']}</warning>");
                continue;
            }
            
            $newEntries[] = [
                'url' => $patch['url'],
                'commit' => $commitHash,
                'provider' => $provider->getIdentifier(),
                'package' => $patch['package'],
                'description' => $patch['description'],
            ];
            
            $io->write("  - Locked {$patch['description']} ({$patch['package']}): " . substr($commitHash, 0, 12) . " [{$provider->getIdentifier()}]");
        }
        
        if (!empty($newEntries)) {
            $this->saveCommitLockToComposerLock($lockFilePath, array_merge($existingCommits, $newEntries));
            $io->write('<info>Patch commit lock: saved ' . count($newEntries) . ' new commit hash(es) to composer.lock</info>');
        }
    }

    // Runs FIRST (priority 1) - Download patches and populate cache BEFORE URL rewriting
    public function downloadPatchesForPackage(\Composer\Installer\PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $package = $operation instanceof \Composer\DependencyResolver\Operation\InstallOperation 
            ? $operation->getPackage() 
            : $operation->getTargetPackage();
        $packageName = $package->getName();
        
        $this->findPatchesPlugin();
        if (!$this->patchesPlugin) {
            $this->io->write("  [DEBUG] No patches plugin found for {$packageName}", true, IOInterface::VERBOSE);
            return;
        }
        
        $patches = $this->getPatchesArray();
        if (!isset($patches[$packageName])) {
            $this->io->write("  [DEBUG] No patches for {$packageName}", true, IOInterface::VERBOSE);
            return;
        }
        
        $this->io->write("  [DEBUG] Processing {$packageName} patches: " . count($patches[$packageName]), true, IOInterface::VERBOSE);
        
        foreach ($patches[$packageName] as $description => $url) {
            // Skip if already a local file
            if (str_starts_with($url, '/') || str_starts_with($url, 'file://')) {
                $this->io->write("  [DEBUG] Skipping local file: {$url}", true, IOInterface::VERBOSE);
                continue;
            }
            
            $provider = $this->providerRegistry->findProviderForUrl($url);
            if (!$provider) {
                $this->io->write("  [DEBUG] No provider for: {$url}", true, IOInterface::VERBOSE);
                continue;
            }
            
            // Check if we have a locked commit in composer.lock
            $lockedCommit = $this->getCommitFromComposerLock($url);
            
            if (!$lockedCommit) {
                $patchExtra = $this->getPatchExtra($url, $description);
                $lockedCommit = $patchExtra['git_commit_hash'] ?? null;
            }
            
            if (!$lockedCommit) {
                // No locked commit, fetch from provider (first time)
                $commitHash = $this->providerRegistry->findProviderForUrl($url)?->fetchCommitHash($url, $this->io);
                if ($commitHash) {
                    $lockedCommit = $commitHash;
                }
            }
            
            if ($lockedCommit) {
                $this->io->write("  [DEBUG] Found locked commit for {$url}: {$lockedCommit}", true, IOInterface::VERBOSE);
                $provider = $this->providerRegistry->findProviderForUrl($url);
                if ($provider) {
                    $providerPatchUrl = $provider->getPatchUrl($url, $lockedCommit);
                    if ($providerPatchUrl) {
                        $localPath = $this->downloadFromProvider($providerPatchUrl);
                        if ($localPath) {
                            // Store for URL rewriting
                            $this->localPatchCache[$url] = $localPath;
                            // Store commit info keyed by local path for later lookup
                            $this->localPatchInfo[$localPath] = [
                                'commit' => $lockedCommit,
                                'provider' => $provider->getIdentifier(),
                                'originalUrl' => $url,
                                'description' => $description,
                                'package' => $packageName,
                            ];
                            $this->io->write("  - Cached patch for {$url}: {$localPath}", true, IOInterface::VERBOSE);
                        }
                    }
                }
            }
        }
    }

    // Runs AFTER download (priority 9) - Rewrite URLs to local cached files
    public function rewritePatchUrlsToLocal(\Composer\Installer\PackageEvent $event): void
    {
        if (!$this->patchesPlugin) {
            $this->findPatchesPlugin();
        }
        
        if (!$this->patchesPlugin || empty($this->localPatchCache)) {
            $this->io->write("  [DEBUG] rewritePatchUrlsToLocal: no cache or plugin", true, IOInterface::VERBOSE);
            return;
        }
        
        $operation = $event->getOperation();
        $package = $operation instanceof \Composer\DependencyResolver\Operation\InstallOperation 
            ? $operation->getPackage() 
            : $operation->getTargetPackage();
        $packageName = $package->getName();
        
        $patches = $this->getPatchesArray();
        
        if (!isset($patches[$packageName])) {
            $this->io->write("  [DEBUG] rewritePatchUrlsToLocal: no patches for {$packageName}", true, IOInterface::VERBOSE);
            return;
        }
        
        $rewritten = false;
        foreach ($patches[$packageName] as $description => $url) {
            if (isset($this->localPatchCache[$url])) {
                // Rewrite URL to local file path
                $patches[$packageName][$description] = $this->localPatchCache[$url];
                $rewritten = true;
                $this->io->write("  - Rewrote patch URL to local file for {$packageName}: {$description}", true, IOInterface::VERBOSE);
            } else {
                $this->io->write("  [DEBUG] No cached file for {$url}", true, IOInterface::VERBOSE);
            }
        }
        
        if ($rewritten) {
            $reflection = new \ReflectionClass($this->patchesPlugin);
            $property = $reflection->getProperty('patches');
            $property->setAccessible(true);
            $property->setValue($this->patchesPlugin, $patches);
        }
    }

    // Runs during patch application - collect commit hashes from localPatchInfo
    public function onPrePatchApply(PatchEvent $event): void
    {
        $patchUrl = $event->getUrl();
        $description = $event->getDescription();
        $packageName = $event->getPackage()->getName();
        
        // If URL is a local file (our rewrite), look up commit info
        if (str_starts_with($patchUrl, '/') || str_starts_with($patchUrl, 'file://')) {
            if (isset($this->localPatchInfo[$patchUrl])) {
                $info = $this->localPatchInfo[$patchUrl];
                $this->io->write("  - Using locked commit for {$description}: {$info['commit']}", true, IOInterface::VERBOSE);
                // Store for post-apply
                $this->commitHashes[$patchUrl] = [
                    'commit' => $info['commit'],
                    'provider' => $info['provider'],
                    'package' => $packageName,
                    'description' => $description,
                    'originalUrl' => $info['originalUrl'],
                ];
            }
            return;
        }
        
        // For non-local URLs (shouldn't happen after rewrite, but handle anyway)
        $provider = $this->providerRegistry->findProviderForUrl($patchUrl);
        if (!$provider) {
            return;
        }
        
        $this->io->write("  - Processing patch with {$provider->getName()}: {$patchUrl}", true, IOInterface::VERBOSE);

        // Get commit hash from patch extra or composer.lock
        $patchExtra = $this->getPatchExtra($patchUrl, $description);
        $lockedCommit = $patchExtra['git_commit_hash'] ?? null;
        
        if (!$lockedCommit) {
            $lockedCommit = $this->getCommitFromComposerLock($patchUrl);
            if ($lockedCommit) {
                $this->io->write("  - Found locked commit in composer.lock: {$lockedCommit}", true, IOInterface::VERBOSE);
            }
        }
        
        if (!$lockedCommit) {
            $commitHash = $provider->fetchCommitHash($patchUrl, $this->io);
            if ($commitHash) {
                $this->io->write("  - Fetched latest commit from {$provider->getName()}: {$commitHash}", true, IOInterface::VERBOSE);
                $lockedCommit = $commitHash;
            }
        } else {
            $this->io->write("  - Using locked commit: {$lockedCommit}", true, IOInterface::VERBOSE);
        }

        if ($lockedCommit) {
            $this->commitHashes[$patchUrl] = [
                'commit' => $lockedCommit,
                'provider' => $provider->getIdentifier(),
                'package' => $packageName,
                'description' => $description,
                'originalUrl' => $patchUrl,
            ];
        }
    }

    public function onPostPatchApply(PatchEvent $event): void
    {
        $patchUrl = $event->getUrl();
        $description = $event->getDescription();
        
        // Use commit hashes collected in onPrePatchApply
        if (isset($this->commitHashes[$patchUrl])) {
            $data = $this->commitHashes[$patchUrl];
            
            // Use originalUrl if available (for rewritten local files)
            $urlForLock = $data['originalUrl'] ?? $patchUrl;
            
            $this->commitHashes[$urlForLock] = [
                'commit' => $data['commit'],
                'provider' => $data['provider'],
                'package' => $data['package'],
                'description' => $data['description'],
            ];
            
            unset($this->commitHashes[$patchUrl]);
            
            $this->saveCommitHashesToRootPackage();
        }
    }

    protected function findPatchesPlugin(): void
    {
        $pluginManager = $this->composer->getPluginManager();
        $plugins = $pluginManager->getPlugins();
        
        foreach ($plugins as $plugin) {
            if ($plugin instanceof BasePatchesPlugin) {
                $this->patchesPlugin = $plugin;
                break;
            }
        }
    }

    protected function getPatchesArray(): array
    {
        if (!$this->patchesPlugin) {
            return [];
        }
        
        $reflection = new \ReflectionClass($this->patchesPlugin);
        $property = $reflection->getProperty('patches');
        $property->setAccessible(true);
        return $property->getValue($this->patchesPlugin) ?? [];
    }

    protected function getPatchExtra(string $patchUrl, string $description): array
    {
        if (!$this->patchesPlugin) {
            $this->findPatchesPlugin();
        }
        
        if (!$this->patchesPlugin) {
            return [];
        }
        
        $patches = $this->getPatchesArray();
        
        foreach ($patches as $packagePatches) {
            if (isset($packagePatches[$description]) && $packagePatches[$description] === $patchUrl) {
                return $packagePatches['extra'] ?? [];
            }
        }
        
        return [];
    }

    protected function getCommitFromComposerLock(string $patchUrl): ?string
    {
        $lockFilePath = $this->composer->getConfig()->get('lock-file') ?? 'composer.lock';
        
        if (!file_exists($lockFilePath)) {
            return null;
        }
        
        $lockData = json_decode(file_get_contents($lockFilePath), true);
        
        if (!isset($lockData['patch-commit-lock'])) {
            return null;
        }
        
        foreach ($lockData['patch-commit-lock'] as $entry) {
            if ($entry['url'] === $patchUrl) {
                return $entry['commit'] ?? null;
            }
        }
        
        return null;
    }

    protected function downloadFromProvider(string $url): ?string
    {
        $composerCache = $this->composer->getConfig()->get('cache-dir');
        if (!is_dir($composerCache)) {
            $composerCache = rtrim(sys_get_temp_dir(), '/');
        }
        $cacheDir = $composerCache . '/patches';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $filename = $cacheDir . '/' . uniqid('patch_') . '.patch';
        
        try {
            $httpDownloader = new \Composer\Util\HttpDownloader($this->io, $this->composer->getConfig());
            $httpDownloader->copy($url, $filename);
            
            if (file_exists($filename) && filesize($filename) > 0) {
                $this->io->write("  - Downloaded from provider: {$url}", true, IOInterface::VERBOSE);
                return $filename;
            }
        } catch (\Exception $e) {
            $this->io->writeError("  - Download failed from {$url}: " . $e->getMessage(), true, IOInterface::VERBOSE);
            if (file_exists($filename)) {
                unlink($filename);
            }
        }
        
        return null;
    }

    protected function saveCommitHashesToRootPackage(): void
    {
        $rootPackage = $this->composer->getPackage();
        $extra = $rootPackage->getExtra();
        
        $patchCommitLock = [];
        foreach ($this->commitHashes as $url => $data) {
            $patchCommitLock[] = [
                'url' => $url,
                'commit' => $data['commit'],
                'provider' => $data['provider'],
                'package' => $data['package'],
                'description' => $data['description'],
            ];
        }
        
        if (!empty($patchCommitLock)) {
            $extra['patch-commit-lock'] = $patchCommitLock;
            $rootPackage->setExtra($extra);
            
            $this->io->write('  - Saved ' . count($patchCommitLock) . ' commit hashes to root package extra for composer.lock', true, IOInterface::VERBOSE);
        }
    }

    protected function getPatchesFromRootPackage(): array
    {
        $extra = $this->composer->getPackage()->getExtra();
        return $extra['patches'] ?? [];
    }

    protected function loadCommitLockFromComposerLock(string $lockFilePath): array
    {
        if (!file_exists($lockFilePath)) {
            return [];
        }
        
        $lockData = json_decode(file_get_contents($lockFilePath), true);
        return $lockData['patch-commit-lock'] ?? [];
    }

    protected function saveCommitLockToComposerLock(string $lockFilePath, array $entries): void
    {
        $lockFile = new \Composer\Json\JsonFile($lockFilePath);
        
        if (!$lockFile->exists()) {
            return;
        }
        
        $composerLockData = $lockFile->read();
        $composerLockData['patch-commit-lock'] = $entries;
        $lockFile->write($composerLockData);
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }
}