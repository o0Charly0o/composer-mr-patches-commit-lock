<?php

namespace Cromero\Composer\PatchesCommitLock\GitProvider;

use Composer\IO\IOInterface;
use Cromero\Composer\PatchesCommitLock\GitProvider\DrupalGitLabProvider;
use Cromero\Composer\PatchesCommitLock\GitProvider\GitHubProvider;

class GitProviderRegistry
{
    /** @var GitProviderInterface[] */
    protected array $providers = [];

    public function __construct(IOInterface $io = null, array $customProviders = [])
    {
        // Register default providers
        $this->register(new DrupalGitLabProvider());
        
        // Register custom providers
        foreach ($customProviders as $provider) {
            if ($provider instanceof GitProviderInterface) {
                $this->register($provider);
            }
        }
        
        if ($io) {
            $this->setIO($io);
        }
    }

    public function register(GitProviderInterface $provider): void
    {
        $this->providers[$provider->getIdentifier()] = $provider;
    }

    public function getProvider(string $identifier): ?GitProviderInterface
    {
        return $this->providers[$identifier] ?? null;
    }

    public function getAllProviders(): array
    {
        return $this->providers;
    }

    public function findProviderForUrl(string $url): ?GitProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($url)) {
                return $provider;
            }
        }
        return null;
    }

    public function setIO(IOInterface $io): void
    {
        foreach ($this->providers as $provider) {
            $provider->setIO($io);
        }
    }
}