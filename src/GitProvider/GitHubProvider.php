<?php

namespace Cromero\Composer\PatchesCommitLock\GitProvider;

use Composer\IO\IOInterface;

class GitHubProvider extends AbstractGitProvider
{
    protected string $apiToken = '';

    public function __construct(string $apiToken = '')
    {
        $this->apiToken = $apiToken;
    }

    public function getName(): string
    {
        return 'GitHub';
    }

    public function getIdentifier(): string
    {
        return 'github';
    }

    protected function extractIssueNumber(string $url): ?string
    {
        // Match GitHub patch URLs: https://github.com/owner/repo/pull/123.patch or https://patch-diff.githubusercontent.com/raw/owner/repo/pull/123.patch
        if (preg_match('#^https?://(?:patch-diff\.githubusercontent\.com/raw/|github\.com/)([^/]+)/([^/]+)/pull/(\d+)\.patch$#i', $url, $matches)) {
            // Return in format "owner/repo#number"
            return $matches[1] . '/' . $matches[2] . '#' . $matches[3];
        }
        
        // Match GitHub commit URLs: https://github.com/owner/repo/commit/abc123.patch
        if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)/commit/([a-f0-9]+)\.patch$#i', $url, $matches)) {
            return $matches[1] . '/' . $matches[2] . '@' . $matches[3];
        }
        
        return null;
    }

    protected function buildApiUrl(string $issueNumber): string
    {
        // Parse owner/repo#number or owner/repo@commit
        if (strpos($issueNumber, '#') !== false) {
            [$repo, $number] = explode('#', $issueNumber);
            return "https://api.github.com/repos/{$repo}/pulls/{$number}";
        } elseif (strpos($issueNumber, '@') !== false) {
            [$repo, $commit] = explode('@', $issueNumber);
            return "https://api.github.com/repos/{$repo}/commits/{$commit}";
        }
        
        return '';
    }

    protected function extractCommitHash(array $response): ?string
    {
        // For PR: return head.sha
        if (isset($response['head']['sha'])) {
            return $response['head']['sha'];
        }
        
        // For commit: return sha directly
        if (isset($response['sha'])) {
            return $response['sha'];
        }
        
        return null;
    }

    protected function buildPatchUrl(string $originalUrl, string $commitHash): ?string
    {
        // GitHub commit patch URL format
        if (preg_match('#^https?://(?:patch-diff\.githubusercontent\.com/raw/|github\.com/)([^/]+)/([^/]+)/#i', $originalUrl, $matches)) {
            return "https://github.com/{$matches[1]}/{$matches[2]}/commit/{$commitHash}.patch";
        }
        
        return null;
    }
}