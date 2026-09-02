<?php

namespace Cromero\Composer\PatchesCommitLock\Command;

use Composer\Command\BaseCommand;
use Cromero\Composer\PatchesCommitLock\GitProvider\GitProviderRegistry;
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
                . 'Example: composer patches:mr-commit https://git.drupalcode.org/project/drupal/-/merge_requests/16765.patch'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $patchUrl = $input->getArgument('patch-url');

        $providerRegistry = new GitProviderRegistry($io);
        $provider = $providerRegistry->findProviderForUrl($patchUrl);

        if (!$provider) {
            $io->writeError("<error>No provider found for URL: {$patchUrl}</error>");
            return 1;
        }

        $commitHash = $provider->fetchCommitHash($patchUrl, $io);

        if ($commitHash) {
            $io->write("<info>Commit hash: {$commitHash}</info>");
            $io->write("<info>Provider: {$provider->getName()}</info>");
            return 0;
        }

        $io->writeError("<error>Could not fetch commit hash for patch: {$patchUrl}</error>");
        return 1;
    }
}
