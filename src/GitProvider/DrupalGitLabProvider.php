<?php

namespace Cromero\Composer\PatchesCommitLock\GitProvider;

use Composer\IO\IOInterface;

class DrupalGitLabProvider extends AbstractGitProvider
{
    public function getName(): string
    {
        return 'Drupal.org (GitLab)';
    }

    public function getIdentifier(): string
    {
        return 'drupal-gitlab';
    }

    protected function extractIssueNumber(string $url): ?string
    {
        // Format 1: Drupal.org issue patches: https://www.drupal.org/files/issues/.../{issue_number}.patch
        if (preg_match('#^https?://(?:www\.)?drupal\.org/files/issues/.*/(\d+)\.patch$#i', $url, $matches)) {
            return 'issue:' . $matches[1];
        }
        
        // Format 2: Direct GitLab MR patches: https://git.drupalcode.org/project/{project}/-/merge_requests/{mr_number}.patch
        if (preg_match('#^https?://git\.drupalcode\.org/project/([^/]+)/-/merge_requests/(\d+)\.patch$#i', $url, $matches)) {
            return 'mr:' . $matches[1] . ':' . $matches[2];
        }
        
        return null;
    }

    protected function buildApiUrl(string $issueNumber): string
    {
        // If it's a direct MR reference (mr:project:mr_number)
        if (str_starts_with($issueNumber, 'mr:')) {
            [$_, $project, $mrNumber] = explode(':', $issueNumber, 3);
            $encodedProject = urlencode("project/$project");
            return "https://git.drupalcode.org/api/v4/projects/{$encodedProject}/merge_requests/{$mrNumber}";
        }
        
        // If it's a Drupal.org issue number (issue:12345)
        if (str_starts_with($issueNumber, 'issue:')) {
            $number = substr($issueNumber, 6);
            // Search in the main Drupal project on GitLab
            return "https://git.drupalcode.org/api/v4/projects/project%2Fdrupal/merge_requests?search={$number}&per_page=20";
        }
        
        return '';
    }

    protected function extractCommitHash(array $response): ?string
    {
        // Direct MR API returns single MR object, not array
        if (isset($response['sha'])) {
            return $response['sha'];
        }
        
        // Search API returns array of MRs
        if (is_array($response) && isset($response[0])) {
            usort($response, function($a, $b) {
                return strtotime($b['updated_at']) - strtotime($a['updated_at']);
            });
            
            if (isset($response[0]['sha'])) {
                return $response[0]['sha'];
            }
        }
        
        return null;
    }

    protected function buildPatchUrl(string $originalUrl, string $commitHash): ?string
    {
        // Extract project from URL for commit patch URL
        if (preg_match('#^https?://git\.drupalcode\.org/project/([^/]+)/#i', $originalUrl, $matches)) {
            $project = $matches[1];
            return "https://git.drupalcode.org/project/{$project}/-/commit/{$commitHash}.patch";
        }
        
        // Fallback to drupal project
        return "https://git.drupalcode.org/project/drupal/-/commit/{$commitHash}.patch";
    }
}