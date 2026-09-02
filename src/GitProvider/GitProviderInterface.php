<?php

namespace Cromero\Composer\PatchesCommitLock\GitProvider;

use Composer\IO\IOInterface;

interface GitProviderInterface
{
    /**
     * Check if this provider can handle the given patch URL.
     */
    public function supports(string $patchUrl): bool;

    /**
     * Get the commit hash for a patch URL.
     * Returns null if not found or not applicable.
     */
    public function fetchCommitHash(string $patchUrl, IOInterface $io): ?string;

    /**
     * Get the direct patch download URL for a specific commit hash.
     * Returns null if cannot construct URL.
     */
    public function getPatchUrl(string $patchUrl, string $commitHash): ?string;

    /**
     * Get the provider name (for logging/debugging).
     */
    public function getName(): string;

    /**
     * Get the provider identifier (for storage).
     */
    public function getIdentifier(): string;
}