<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Llm;

use Genaker\Bundle\OroAI\Core\Contract\LlmClientInterface;
use Genaker\Bundle\OroAI\Service\OroAiConfig;

class LlmClientRegistry
{
    /** @var array<string, LlmClientInterface> */
    private array $clients = [];

    /**
     * @param iterable<LlmClientInterface> $clients
     */
    public function __construct(
        iterable $clients,
        private readonly OroAiConfig $oroAiConfig,
    ) {
        foreach ($clients as $client) {
            $this->clients[$client->getName()] = $client;
        }
    }

    public function get(?string $name = null): LlmClientInterface
    {
        $name ??= $this->getDefault();

        if (!isset($this->clients[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown LLM client "%s". Available: %s',
                $name,
                implode(', ', array_keys($this->clients)),
            ));
        }

        return $this->clients[$name];
    }

    /** @return string[] */
    public function getAvailableNames(): array
    {
        return array_keys($this->clients);
    }

    private function getDefault(): string
    {
        return $this->oroAiConfig->getProvider();
    }
}
