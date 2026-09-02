<?php

namespace Cromero\Composer\PatchesCommitLock;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\EventDispatcher\EventSubscriberInterface;
use cweagans\Composer\Event\PatchEvent;
use cweagans\Composer\Event\PatchEvents;
use cweagans\Composer\Plugin\Patches as BasePatchesPlugin;
use cweagans\Composer\Patch;
use cweagans\Composer\PatchCollection;
use Cromero\Composer\PatchesCommitLock\Capability\CommandProvider;
use Cromero\Composer\PatchesCommitLock\GitProvider\GitProviderRegistry;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    protected Composer $composer;
    protected IOInterface $io;
    protected GitProviderRegistry $providerRegistry;
    protected ?BasePatchesPlugin $patchesPlugin = null;
    protected array $commitHashes = [];
    protected array $localPatchInfo = [];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->providerRegistry = new GitProviderRegistry($io);
        $this->findPatchesPlugin();
        $this->io->write('<info>Git Patch Commit Lock plugin activated (v2.x)</info>', true, IOInterface::VERBOSE);
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
            'pre-install-cmd' => 'autoLockMissingCommits',
            'pre-update-cmd' => 'autoLockMissingCommits',
            PatchEvents::PRE_PATCH_DOWNLOAD => 'onPrePatchDownload',
            PatchEvents::PRE_PATCH_APPLY => 'onPrePatchApply',
            PatchEvents::POST_PATCH_APPLY => 'onPostPatchApply',
        ];
    }

    public function onPrePatchDownload(PatchEvent $event): void
    {
        $patch = $event->getPatch();

        if (!str_starts_with($patch->url, 'https://git.drupalcode.org')) {
            return;
        }

        $lockedCommit = $this->getCommitFromComposerLock($patch->url);
        if (!$lockedCommit && isset($patch->extra['git_commit_hash'])) {
            $lockedCommit = $patch->extra['git_commit_hash'];
        }
        if (!$lockedCommit) {
            return;
        }

        $provider = $this->providerRegistry->findProviderForUrl($patch->url);
        if (!$provider) {
            return;
        }

        $lockedUrl = $provider->getPatchUrl($patch->url, $lockedCommit);
        if ($lockedUrl) {
            $originalUrl = $patch->url;
            $patch->url = $lockedUrl;
            $patch->extra['original_url'] = $originalUrl;
            $this->io->write("  - Locked patch to commit: {$lockedCommit} ({$patch->description})", true, IOInterface::VERBOSE);

            $this->localPatchInfo[$lockedUrl] = [
                'commit' => $lockedCommit,
                'provider' => $provider->getIdentifier(),
                'originalUrl' => $originalUrl,
                'description' => $patch->description,
                'package' => $patch->package,
            ];
        }
    }

    public function onPrePatchApply(PatchEvent $event): void
    {
        $patch = $event->getPatch();
        $url = $patch->url;
        $description = $patch->description;

        if (!isset($this->localPatchInfo[$url])) {
            $lockedCommit = $this->getCommitFromComposerLock($patch->extra['original_url'] ?? $url);
            if ($lockedCommit) {
                $provider = $this->providerRegistry->findProviderForUrl($patch->extra['original_url'] ?? $url);
                $this->localPatchInfo[$url] = [
                    'commit' => $lockedCommit,
                    'provider' => $provider?->getIdentifier() ?? 'unknown',
                    'originalUrl' => $patch->extra['original_url'] ?? $url,
                    'description' => $description,
                    'package' => $patch->package,
                ];
            }
        }

        if (isset($this->localPatchInfo[$url])) {
            $info = $this->localPatchInfo[$url];
            $this->io->write("  - Using locked commit for {$description}: {$info['commit']}", true, IOInterface::VERBOSE);
            $this->commitHashes[$info['originalUrl']] = $info;
        }
    }

    public function onPostPatchApply(PatchEvent $event): void
    {
        $patch = $event->getPatch();

        $originalUrl = $patch->extra['original_url'] ?? $patch->url;
        if (isset($this->commitHashes[$originalUrl])) {
            $this->saveCommitHashesToRootPackage();
        }
    }

    public function autoLockMissingCommits(\Composer\Script\Event $event): void
    {
        $patches = $this->getPatchesFromRootPackage();
        if (empty($patches)) {
            return;
        }

        $lockFilePath = $this->composer->getConfig()->get('lock-file') ?? 'composer.lock';
        $existingCommits = $this->loadCommitLockFromComposerLock($lockFilePath);

        $existingByUrl = [];
        foreach ($existingCommits as $entry) {
            $existingByUrl[$entry['url']] = $entry;
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
                if (isset($existingByUrl[$url])) {
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
            $this->io->write('<info>Patch commit lock: all git.drupalcode.org patches are locked</info>', true, IOInterface::VERBOSE);
            return;
        }

        $this->io->write('<info>Patch commit lock: ' . count($missingPatches) . ' patch(es) missing from lock, fetching...</info>');

        $newEntries = [];
        foreach ($missingPatches as $patch) {
            $provider = $this->providerRegistry->findProviderForUrl($patch['url']);
            if (!$provider) {
                continue;
            }

            $commitHash = $provider->fetchCommitHash($patch['url'], $this->io);
            if (!$commitHash) {
                $this->io->writeError("  <warning>Could not fetch commit for: {$patch['url']}</warning>");
                continue;
            }

            $newEntries[] = [
                'url' => $patch['url'],
                'commit' => $commitHash,
                'provider' => $provider->getIdentifier(),
                'package' => $patch['package'],
                'description' => $patch['description'],
            ];

            $this->io->write("  - Locked {$patch['description']} ({$patch['package']}): " . substr($commitHash, 0, 12) . " [{$provider->getIdentifier()}]");
        }

        if (!empty($newEntries)) {
            $this->saveCommitLockToComposerLock($lockFilePath, array_merge($existingCommits, $newEntries));
            $this->io->write('<info>Patch commit lock: saved ' . count($newEntries) . ' new commit hash(es) to composer.lock</info>');
        }
    }

    protected function findPatchesPlugin(): void
    {
        $pluginManager = $this->composer->getPluginManager();
        foreach ($pluginManager->getPlugins() as $plugin) {
            if ($plugin instanceof BasePatchesPlugin) {
                $this->patchesPlugin = $plugin;
                break;
            }
        }
    }

    protected function getPatchCollection(): ?PatchCollection
    {
        if (!$this->patchesPlugin) {
            $this->findPatchesPlugin();
        }
        return $this->patchesPlugin?->getPatchCollection();
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
            $this->io->write('  - Saved ' . count($patchCommitLock) . ' commit hash(es) to root package extra for composer.lock', true, IOInterface::VERBOSE);
        }
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }
}
