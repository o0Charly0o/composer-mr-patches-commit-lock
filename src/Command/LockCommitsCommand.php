<?php

namespace Cromero\Composer\PatchesCommitLock\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Util\HttpDownloader;
use Cromero\Composer\PatchesCommitLock\GitProvider\GitProviderRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LockCommitsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('patches:lock-commits')
            ->setDescription('Lock Git patch commit hashes from git.drupalcode.org to composer.lock')
            ->setHelp(
                'This command reads patches from composer.json (extra.patches),'
                . ' finds patches from git.drupalcode.org,'
                . ' extracts commit hashes from GitLab API,'
                . ' and writes the commit hashes to composer.lock.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $composer = $this->getComposer();
        
        $lockFilePath = $composer->getConfig()->get('lock-file') ?? 'composer.lock';
        
        // Load patches from composer.json extra.patches
        $patches = $this->loadPatchesFromComposerJson($composer, $io);
        
        // Filter only git.drupalcode.org patches
        $drupalCodePatches = [];
        foreach ($patches as $package => $patchList) {
            foreach ($patchList as $patch) {
                if (str_starts_with($patch['url'], 'https://git.drupalcode.org')) {
                    $drupalCodePatches[$package][] = $patch;
                }
            }
        }
        
        if (empty($drupalCodePatches)) {
            $io->write('<info>No git.drupalcode.org patches found in composer.json</info>');
            return 0;
        }
        
        $io->write('<info>Found ' . count($drupalCodePatches, COUNT_RECURSIVE) . ' git.drupalcode.org patches to process</info>');
        
        $providerRegistry = new GitProviderRegistry($io);
        $commitLockData = [];
        
        foreach ($drupalCodePatches as $package => $patchList) {
            foreach ($patchList as $patch) {
                $provider = $providerRegistry->findProviderForUrl($patch['url']);
                if (!$provider) {
                    continue;
                }
                
                $commitHash = $patch['extra']['git_commit_hash'] ?? null;
                
                if (!$commitHash) {
                    $io->write("  - Fetching commit hash for: {$patch['url']}");
                    $commitHash = $provider->fetchCommitHash($patch['url'], $io);
                    if ($commitHash) {
                        $patch['extra']['git_commit_hash'] = $commitHash;
                        $patch['extra']['git_provider'] = $provider->getIdentifier();
                        $io->write("    Found commit: {$commitHash}");
                    } else {
                        $io->writeError("    <error>Could not fetch commit hash</error>");
                    }
                } else {
                    $io->write("  - Using existing commit: {$commitHash}");
                }
                
                if ($commitHash) {
                    $patchUrl = $provider->getPatchUrl($patch['url'], $commitHash);
                    $providerSha256 = null;
                    
                    if ($patchUrl) {
                        $io->write("  - Downloading patch from commit...");
                        $providerSha256 = $this->downloadAndGetSha256($patchUrl, $io);
                    }
                    
                    if ($providerSha256) {
                        $io->write("    Patch sha256: {$providerSha256}");
                    }
                    
                    $commitLockData[] = [
                        'url' => $patch['url'],
                        'commit' => $commitHash,
                        'provider' => $provider->getIdentifier(),
                        'package' => $package,
                        'description' => $patch['description'],
                    ];
                }
            }
        }
        
        if (empty($commitLockData)) {
            $io->writeError('<error>No commit hashes could be retrieved</error>');
            return 1;
        }
        
        // Update composer.lock with patch-commit-lock section
        $lockFile = new JsonFile($lockFilePath);
        
        if (!$lockFile->exists()) {
            $io->writeError('<error>composer.lock not found</error>');
            return 1;
        }
        
        $composerLockData = $lockFile->read();
        $composerLockData['patch-commit-lock'] = $commitLockData;
        $lockFile->write($composerLockData);
        
        $io->write('<info>Successfully locked ' . count($commitLockData) . ' commit hashes to composer.lock</info>');
        
        foreach ($commitLockData as $data) {
            $io->write('  - ' . $data['description'] . ' (' . $data['package'] . '): ' . substr($data['commit'], 0, 12) . ' [' . $data['provider'] . ']');
        }
        
        return 0;
    }
    
    /**
     * Load patches from composer.json extra.patches
     */
    protected function loadPatchesFromComposerJson(Composer $composer, IOInterface $io): array
    {
        $rootPackage = $composer->getPackage();
        $extra = $rootPackage->getExtra();
        
        if (!isset($extra['patches']) || empty($extra['patches'])) {
            $io->writeError('<error>No patches found in composer.json extra.patches</error>');
            return [];
        }
        
        $patches = [];
        foreach ($extra['patches'] as $package => $patchDefs) {
            $patches[$package] = [];
            
            foreach ($patchDefs as $description => $url) {
                if (is_array($url)) {
                    // Expanded format
                    $patch = [
                        'package' => $package,
                        'description' => $description,
                        'url' => $url['url'],
                        'sha256' => $url['sha256'] ?? null,
                        'depth' => $url['depth'] ?? null,
                        'extra' => $url['extra'] ?? [],
                    ];
                } else {
                    // Compact format: description => url
                    $patch = [
                        'package' => $package,
                        'description' => $description,
                        'url' => $url,
                        'sha256' => null,
                        'depth' => null,
                        'extra' => [],
                    ];
                }
                $patches[$package][] = $patch;
                $io->write("  - Found patch: {$package} / {$description} -> {$url}");
            }
        }
        
        return $patches;
    }
    
    protected function downloadAndGetSha256(string $url, IOInterface $io): ?string
    {
        $composer = $this->getComposer();
        $httpDownloader = new HttpDownloader($io, $composer->getConfig());
        
        $composerCache = $composer->getConfig()->get('cache-dir');
        if (!is_dir($composerCache)) {
            $composerCache = rtrim(sys_get_temp_dir(), '/');
        }
        $cacheDir = $composerCache . '/patches';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $filename = $cacheDir . '/' . uniqid('patch_') . '.patch';
        
        try {
            $httpDownloader->copy($url, $filename);
            
            if (file_exists($filename) && filesize($filename) > 0) {
                $hash = hash_file('sha256', $filename);
                unlink($filename);
                return $hash;
            }
        } catch (\Exception $e) {
            if (file_exists($filename)) {
                unlink($filename);
            }
        }
        
        return null;
    }
}