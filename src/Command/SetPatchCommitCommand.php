<?php

namespace Cromero\Composer\PatchesCommitLock\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Cromero\Composer\PatchesCommitLock\GitProvider\GitProviderRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class SetPatchCommitCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('patches:set-commit')
            ->setDescription('Set a specific commit hash for a patch URL in composer.lock')
            ->setDefinition([
                new InputArgument('patch-url', InputArgument::REQUIRED, 'The patch URL (e.g., https://git.drupalcode.org/project/drupal/-/merge_requests/16765.patch)'),
                new InputArgument('commit-hash', InputArgument::REQUIRED, 'The commit hash to lock to (e.g., de91ae6c1c51f71fe2cb778079fa3b86a5b147b4)'),
            ])
            ->setHelp(
                'This command sets a specific commit hash for a patch URL in composer.lock.'
                . ' Useful when you need to lock to a specific commit that is not the latest one.'
                . PHP_EOL . PHP_EOL
                . 'Example: composer patches:set-commit https://git.drupalcode.org/project/admin_toolbar/-/merge_requests/111.patch abc123def456'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $composer = $this->getComposer();
        
        $patchUrl = $input->getArgument('patch-url');
        $commitHash = $input->getArgument('commit-hash');
        
        // Validate URL
        if (!str_starts_with($patchUrl, 'https://git.drupalcode.org/')) {
            $io->writeError('<error>Error: Only git.drupalcode.org URLs are supported</error>');
            return 1;
        }
        
        // Validate commit hash format (hex, 7-40 chars)
        if (!preg_match('/^[a-f0-9]{7,40}$/i', $commitHash)) {
            $io->writeError('<error>Error: Invalid commit hash format. Expected 7-40 hex characters.</error>');
            return 1;
        }
        
        $providerRegistry = new GitProviderRegistry($io);
        $provider = $providerRegistry->findProviderForUrl($patchUrl);
        
        if (!$provider) {
            $io->writeError('<error>Error: No provider found for URL: {$patchUrl}</error>');
            return 1;
        }
        
        // Verify commit exists on remote
        $io->write("<info>Verifying commit {$commitHash} exists on {$provider->getName()}...</info>");
        
        $patchUrlFromCommit = $provider->getPatchUrl($patchUrl, $commitHash);
        if (!$patchUrlFromCommit) {
            $io->writeError("<error>Error: Could not build patch URL for this commit</error>");
            return 1;
        }
        
        $httpDownloader = new \Composer\Util\HttpDownloader($io, $composer->getConfig());
        try {
            $response = $httpDownloader->get($patchUrlFromCommit);
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $io->writeError("<error>Error: Commit {$commitHash} not found on remote (HTTP {$statusCode})</error>");
                return 1;
            }
        } catch (\Exception $e) {
            $io->writeError("<error>Error: Could not verify commit on remote: {$e->getMessage()}</error>");
            return 1;
        }
        
        $io->write("  <info>Commit {$commitHash} verified</info>");
        
        // Determine package and description from composer.json
        $package = null;
        $description = null;
        $extra = $composer->getPackage()->getExtra();
        
        if (isset($extra['patches'])) {
            foreach ($extra['patches'] as $pkg => $patchDefs) {
                foreach ($patchDefs as $desc => $url) {
                    if (is_string($url) && $url === $patchUrl) {
                        $package = $pkg;
                        $description = $desc;
                        break 2;
                    }
                }
            }
        }
        
        if (!$package || !$description) {
            $io->writeError('<error>Error: Could not find patch in composer.json extra.patches</error>');
            $io->writeError('<info>Tip: Add the patch URL to composer.json extra.patches first</info>');
            return 1;
        }
        
        // Update composer.lock
        $lockFilePath = $composer->getConfig()->get('lock-file') ?? 'composer.lock';
        $lockFile = new JsonFile($lockFilePath);
        
        if (!$lockFile->exists()) {
            $io->writeError('<error>composer.lock not found</error>');
            return 1;
        }
        
        $composerLockData = $lockFile->read();
        $existing = $composerLockData['patch-commit-lock'] ?? [];
        
        // Remove existing entry for this URL
        $existing = array_filter($existing, fn($e) => $e['url'] !== $patchUrl);
        
        // Add new entry
        $existing[] = [
            'url' => $patchUrl,
            'commit' => $commitHash,
            'provider' => $provider->getIdentifier(),
            'package' => $package,
            'description' => $description,
        ];
        
        $composerLockData['patch-commit-lock'] = array_values($existing);
        $lockFile->write($composerLockData);
        
        $io->write('<info>Updated composer.lock:</info>');
        $io->write("  - {$description} ({$package}): {$commitHash} [{$provider->getIdentifier()}]");
        
        return 0;
    }
}