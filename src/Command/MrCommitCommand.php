<?php

namespace Cromero\Composer\PatchesCommitLock\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class MrCommitCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('patches:mr-commit')
            ->setDescription('Fetch and display the commit hash for a Drupal.org MR patch')
            ->setDefinition([
                new InputArgument('patch-url', InputArgument::REQUIRED, 'The Drupal.org patch URL'),
            ])
            ->setHelp(
                'This command fetches the commit hash from a Drupal.org merge request patch.'
                . PHP_EOL . PHP_EOL
                . 'Example: composer patches:mr-commit https://www.drupal.org/files/issues/2023-03-31/2844620.patch'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $patchUrl = $input->getArgument('patch-url');

        // Create a mock plugin interface since we're not in the full plugin context
        $plugin = $this->getComposer()->getPluginManager()->getPlugins()[0] ?? null;
        
        $downloader = new \Cromero\Composer\PatchesCommitLock\Downloader\DrupalMrDownloader(
            $this->getComposer(),
            $io,
            $plugin
        );

        $commitHash = $downloader->fetchLatestMrCommitHash($patchUrl);
        
        if ($commitHash) {
            $io->write("<info>Commit hash: {$commitHash}</info>");
            return 0;
        } else {
            $io->writeError("<error>Could not fetch commit hash for patch: {$patchUrl}</error>");
            return 1;
        }
    }
}