<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Settings;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Resolved runtime settings for one named storage connection.
 *
 * JSON serialization masks credential-like values and is safe for diagnostics.
 */
class StorageSettings implements JsonSerializable
{
    public function __construct(
        public readonly string $diskName,
        public readonly array $config,
        public readonly string $provider,
        public readonly string $source = 'config',
        public readonly bool $enabled = true,
    ) {
        if ($diskName === '') {
            throw new InvalidArgumentException('Storage connection name must not be empty.');
        }

        if ($provider === '') {
            throw new InvalidArgumentException('Storage provider identifier must not be empty.');
        }
    }

    public function withName(string $name): self
    {
        return new self($name, $this->config, $this->provider, $this->source, $this->enabled);
    }

    public function jsonSerialize(): array
    {
        return [
            'diskName' => $this->diskName,
            'provider' => $this->provider,
            'source' => $this->source,
            'enabled' => $this->enabled,
            'config' => $this->redact($this->config),
        ];
    }

    private function redact(array $configuration): array
    {
        foreach ($configuration as $key => $value) {
            if (preg_match('/^(key|.*private.?key.*|.*secret.*|.*password.*|.*token.*|.*credential.*|.*authorization.*|.*access.?key.*)$/i', (string) $key) === 1) {
                $configuration[$key] = '********';
            } elseif (is_array($value)) {
                $configuration[$key] = $this->redact($value);
            } elseif (is_object($value)) {
                $configuration[$key] = '[redacted object]';
            } elseif (is_resource($value)) {
                $configuration[$key] = '[redacted resource]';
            }
        }

        return $configuration;
    }
}
