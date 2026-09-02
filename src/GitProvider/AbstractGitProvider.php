<?php

namespace Cromero\Composer\PatchesCommitLock\GitProvider;

use Composer\IO\IOInterface;
use Cromero\Composer\PatchesCommitLock\PatchInfo;

abstract class AbstractGitProvider implements GitProviderInterface
{
    protected IOInterface $io;

    public function setIO(IOInterface $io): void
    {
        $this->io = $io;
    }

    /**
     * Extract issue/ticket number from a URL.
     * Must be implemented by subclasses.
     */
    abstract protected function extractIssueNumber(string $url): ?string;

    /**
     * Build the API URL to search for MRs/PRs related to an issue.
     * Must be implemented by subclasses.
     */
    abstract protected function buildApiUrl(string $issueNumber): string;

    /**
     * Extract commit hash from API response.
     * Must be implemented by subclasses.
     */
    abstract protected function extractCommitHash(array $response): ?string;

    /**
     * Build the direct patch download URL for a commit.
     * Must be implemented by subclasses.
     */
    abstract protected function buildPatchUrl(string $originalUrl, string $commitHash): ?string;

    public function fetchCommitHash(string $patchUrl, IOInterface $io): ?string
    {
        $issueNumber = $this->extractIssueNumber($patchUrl);
        if (!$issueNumber) {
            return null;
        }

        $apiUrl = $this->buildApiUrl($issueNumber);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => 'User-Agent: Composer-Patches-CommitLock/1.0'
            ]
        ]);

        $response = @file_get_contents($apiUrl, false, $context);
        if (!$response) {
            $this->io->writeError("  - Warning: Could not fetch data from {$this->getName()} API for issue #{$issueNumber}", true, IOInterface::VERBOSE);
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data)) {
            return null;
        }

        return $this->extractCommitHash($data);
    }

    public function getPatchUrl(string $patchUrl, string $commitHash): ?string
    {
        return $this->buildPatchUrl($patchUrl, $commitHash);
    }

    public function supports(string $patchUrl): bool
    {
        return $this->extractIssueNumber($patchUrl) !== null;
    }
}