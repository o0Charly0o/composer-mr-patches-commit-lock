# Composer Patches Commit Lock

A Composer plugin that extends [cweagans/composer-patches](https://github.com/cweagans/composer-patches) to lock Git patch commits from various providers (Drupal.org, GitHub, GitLab, etc.) in `composer.lock`.

## Problem

When using patches from Git hosting providers (Drupal.org MRs, GitHub PRs, GitLab MRs), the patch URL often returns the **latest** version of the patch. If a new commit is pushed to the branch, the patch content changes.

This causes issues during deployment:
- If your `composer.lock` has the old patch content, a new deploy will fail
- If you don't have a lock file, the new patch code gets applied unexpectedly

## Solution

This plugin:
1. Detects Git patch URLs from supported providers
2. Fetches the commit hash from the provider's API
3. Downloads the patch for that specific commit
4. Stores the commit hash and provider in `composer.lock` under `patch-commit-lock` section
5. On subsequent installs, uses the locked commit hash to download the exact same patch

## Installation

```bash
composer require cromero/composer-mr-patches-commit-lock
```

Make sure you also have `cweagans/composer-patches` installed:

```bash
composer require cweagans/composer-patches
```

## Usage

Define your patches as usual in `composer.json`:

```json
{
  "extra": {
    "patches": {
      "drupal/core": {
        "Fix something": "https://www.drupal.org/files/issues/2023-03-31/2844620.patch"
      },
      "drupal/admin_toolbar": {
        "Route fix": "https://git.drupalcode.org/project/admin_toolbar/-/merge_requests/111.patch"
      }
    }
  }
}
```

## How it works

### Automatic lock on install/update

The plugin automatically checks if all `git.drupalcode.org` patches are locked in `composer.lock` when you run:

```bash
composer install
composer update
```

If any patches are missing from the lock, it will:
1. Fetch the commit hash from GitLab API
2. Save it to `composer.lock` under `patch-commit-lock`
3. Continue with the normal install/update process

Example output:
```
Patch commit lock: 1 patch(es) missing from lock, fetching...
  - Locked Route update.theme_install does not exist (drupal/admin_toolbar): 8324aed07282 [drupal-gitlab]
Patch commit lock: saved 1 new commit hash(es) to composer.lock
```

### Manual lock commands

#### Lock all patches

```bash
composer patches:lock-commits
```

Scans all `git.drupalcode.org` patches in `composer.json` and locks their commit hashes to `composer.lock`.

#### Lock a single patch

```bash
composer patches:lock-commit https://git.drupalcode.org/project/drupal/-/merge_requests/16765.patch
```

#### Set a specific commit hash

```bash
composer patches:set-commit <patch-url> <commit-hash>
```

Example:
```bash
composer patches:set-commit https://git.drupalcode.org/project/admin_toolbar/-/merge_requests/111.patch 8324aed072828d1c617fbc3f3daedaa7502f41a5
```

This is useful when you need to lock to a specific commit that is not the latest one. The command:
- Validates the URL is from a supported provider
- Validates the commit hash format (7-40 hex characters)
- Verifies the commit exists on the remote
- Saves to `composer.lock`

#### Get commit hash for a patch (testing)

```bash
composer patches:mr-commit https://git.drupalcode.org/project/drupal/-/merge_requests/16765.patch
```

## Supported Providers

| Provider | URL Pattern | Identifier |
|----------|-------------|------------|
| Drupal.org (GitLab) | `https://git.drupalcode.org/project/{project}/-/merge_requests/{mr}.patch` | `drupal-gitlab` |
| Drupal.org (issues) | `https://www.drupal.org/files/issues/{date}/{issue}.patch` | `drupal-gitlab` |
| GitHub PR | `https://github.com/owner/repo/pull/123.patch` | `github` |
| GitHub (patch-diff) | `https://patch-diff.githubusercontent.com/raw/owner/repo/pull/123.patch` | `github` |
| GitHub Commit | `https://github.com/owner/repo/commit/abc123.patch` | `github` |

## Example `composer.lock` output

```json
{
  "patch-commit-lock": [
    {
      "url": "https://git.drupalcode.org/project/drupal/-/merge_requests/16765.patch",
      "commit": "de91ae6c1c51f71fe2cb778079fa3b86a5b147b4",
      "provider": "drupal-gitlab",
      "package": "drupal/core",
      "description": "Exposed forms in a block are not updated by AJAX"
    },
    {
      "url": "https://git.drupalcode.org/project/admin_toolbar/-/merge_requests/111.patch",
      "commit": "8324aed072828d1c617fbc3f3daedaa7502f41a5",
      "provider": "drupal-gitlab",
      "package": "drupal/admin_toolbar",
      "description": "Route update.theme_install does not exist"
    }
  ]
}
```

## Commands Reference

| Command | Description |
|---------|-------------|
| `composer patches:lock-commits` | Lock all git.drupalcode.org patches to composer.lock |
| `composer patches:lock-commit <url>` | Lock a single patch to composer.lock |
| `composer patches:set-commit <url> <hash>` | Set a specific commit hash for a patch |
| `composer patches:mr-commit <url>` | Fetch and display commit hash (testing) |

## Extending with Custom Providers

Create a custom provider by implementing `GitProviderInterface` or extending `AbstractGitProvider`:

```php
<?php

namespace MyProject\GitProvider;

use Cromero\Composer\PatchesCommitLock\GitProvider\AbstractGitProvider;
use Composer\IO\IOInterface;

class CustomGitLabProvider extends AbstractGitProvider
{
    public function getName(): string
    {
        return 'Custom GitLab';
    }

    public function getIdentifier(): string
    {
        return 'custom-gitlab';
    }

    protected function extractIssueNumber(string $url): ?string
    {
        if (preg_match('#^https?://gitlab\.mycompany\.com/([^/]+)/([^/]+)/merge_requests/(\d+)\.patch$#i', $url, $matches)) {
            return $matches[1] . '/' . $matches[2] . '#' . $matches[3];
        }
        return null;
    }

    protected function buildApiUrl(string $issueNumber): string
    {
        [$project, $mrId] = explode('#', $issueNumber);
        return "https://gitlab.mycompany.com/api/v4/projects/" . urlencode($project) . "/merge_requests/{$mrId}";
    }

    protected function extractCommitHash(array $response): ?string
    {
        return $response['sha'] ?? null;
    }

    protected function buildPatchUrl(string $originalUrl, string $commitHash): ?string
    {
        return "https://gitlab.mycompany.com/..."; // Your patch URL format
    }
}
```

## Requirements

- PHP 8.1+
- Composer 2.x
- cweagans/composer-patches ^1.7
- Internet access to provider APIs

## License

BSD-3-Clause