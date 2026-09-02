<?php

namespace Cromero\Composer\PatchesCommitLock\Capability;

use Composer\Plugin\Capability\CommandProvider as CommandProviderInterface;
use Cromero\Composer\PatchesCommitLock\Command\LockCommitsCommand;
use Cromero\Composer\PatchesCommitLock\Command\MrCommitCommand;
use Cromero\Composer\PatchesCommitLock\Command\SetPatchCommitCommand;

class CommandProvider implements CommandProviderInterface
{
    public function getCommands(): array
    {
        return [
            new LockCommitsCommand(),
            new MrCommitCommand(),
            new SetPatchCommitCommand(),
        ];
    }
}