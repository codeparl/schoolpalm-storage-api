<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers;

use SchoolPalm\StorageApi\Contracts\StorageCapabilities;
use SchoolPalm\StorageApi\Exceptions\InvalidStorageConfigurationException;

final readonly class ProviderDefinition
{
    /**
     * @param  list<ConfigurationField>  $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $driver,
        public array $fields,
        public StorageCapabilities $capabilities,
        public string $description = '',
        public array $authorization = [],
    ) {}

    public static function local(): self
    {
        return new self(
            'local',
            'Local filesystem',
            'local',
            [
                ConfigurationField::string('root')
                    ->label('Storage root')
                    ->required(false)
                    ->description('Filesystem directory used as the storage root.'),
                ConfigurationField::string('visibility')
                    ->label('Visibility')
                    ->required(false)
                    ->default('private'),
            ],
            StorageCapabilities::local(),
            'Store files on the application server.',
        );
    }

    public static function s3(): self
    {
        return new self(
            's3',
            'Amazon S3 / S3-compatible',
            's3',
            [
                ConfigurationField::string('key')->label('Access key ID')->required(false)->secret(true),
                ConfigurationField::string('secret')->label('Secret access key')->required(false)->secret(true),
                ConfigurationField::string('region')->label('Region')->default('us-east-1'),
                ConfigurationField::string('bucket')->label('Bucket'),
                ConfigurationField::url('endpoint')->label('Custom endpoint')->required(false),
                ConfigurationField::boolean('use_path_style_endpoint')->label('Use path-style endpoint'),
                ConfigurationField::string('url')->label('Public base URL')->required(false)->type('url'),
                ConfigurationField::string('prefix')->label('Key prefix')->required(false),
                ConfigurationField::string('visibility')->label('Visibility')->required(false)->default('private'),
            ],
            StorageCapabilities::s3(),
            'Amazon S3 and S3-compatible services with the Laravel AWS adapter installed.',
        );
    }

    public static function gdrive(): self
    {
        return new self(
            'gdrive',
            'Google Drive',
            'google-drive',
            [
                ConfigurationField::string('clientId')->label('OAuth client ID'),
                ConfigurationField::string('clientSecret')->label('OAuth client secret')->secret(true),
                ConfigurationField::string('refreshToken')->label('Refresh token')->required(false)->secret(true),
                ConfigurationField::string('accessToken')->label('Access token')->required(false)->secret(true),
                ConfigurationField::string('accessTokenExpiresAt')->label('Access token expiry')->required(false),
                ConfigurationField::string('folderId')->label('Drive folder ID')->required(false),
                ConfigurationField::string('teamDriveId')->label('Shared drive ID')->required(false),
            ],
            StorageCapabilities::gdrive(),
            'Google Drive storage using the package-provided Flysystem adapter and OAuth authorization.',
            [
                'required' => true,
                'flow' => 'oauth2_authorization_code',
                'scopes' => ['https://www.googleapis.com/auth/drive.file'],
                'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            ],
        );
    }

    public static function dropbox(): self
    {
        return new self(
            'dropbox',
            'Dropbox',
            'dropbox',
            [
                ConfigurationField::string('clientId')->label('OAuth app key'),
                ConfigurationField::string('clientSecret')->label('OAuth app secret')->secret(true),
                ConfigurationField::string('refreshToken')->label('Refresh token')->required(false)->secret(true),
                ConfigurationField::string('accessToken')->label('Access token')->required(false)->secret(true),
                ConfigurationField::string('accessTokenExpiresAt')->label('Access token expiry')->required(false),
                ConfigurationField::string('root')->label('App folder path')->required(false)->default(''),
            ],
            StorageCapabilities::dropbox(),
            'Dropbox storage using the package-provided Flysystem adapter and OAuth authorization.',
            [
                'required' => true,
                'flow' => 'oauth2_authorization_code',
                'scopes' => [
                    'files.content.read',
                    'files.content.write',
                    'files.metadata.read',
                    'files.metadata.write',
                ],
                'authorization_url' => 'https://www.dropbox.com/oauth2/authorize',
            ],
        );
    }

    public static function onedrive(): self
    {
        return new self(
            'onedrive',
            'Microsoft OneDrive',
            'onedrive',
            [
                ConfigurationField::string('clientId')->label('OAuth client ID'),
                ConfigurationField::string('clientSecret')->label('OAuth client secret')->secret(true),
                ConfigurationField::string('refreshToken')->label('Refresh token')->required(false)->secret(true),
                ConfigurationField::string('accessToken')->label('Access token')->required(false)->secret(true),
                ConfigurationField::string('accessTokenExpiresAt')->label('Access token expiry')->required(false),
                ConfigurationField::string('tenantId')->label('Microsoft tenant')->required(false)->default('common'),
                ConfigurationField::string('driveId')->label('OneDrive ID')->required(false),
            ],
            StorageCapabilities::onedrive(),
            'Microsoft OneDrive storage using the package-provided Flysystem adapter and OAuth authorization.',
            [
                'required' => true,
                'flow' => 'oauth2_authorization_code',
                'scopes' => ['offline_access', 'Files.ReadWrite', 'User.Read'],
                'authorization_url' => 'https://login.microsoftonline.com/{tenantId}/oauth2/v2.0/authorize',
            ],
        );
    }

    public function validate(array $configuration): void
    {
        $errors = [];

        foreach ($this->fields as $field) {
            $value = $configuration[$field->name] ?? null;
            if (($value === null || $value === '') && $field->required && $field->default === null) {
                $errors[] = $field->name;

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }

            $valid = match ($field->type) {
                'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
                'number' => is_numeric($value),
                'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
                'select' => in_array($value, array_keys($field->options), true)
                    || in_array($value, $field->options, true),
                default => is_string($value) || is_numeric($value),
            };

            foreach ($field->rules as $rule => $limit) {
                if ($rule === 'min' && is_string($value) && strlen($value) < $limit) {
                    $valid = false;
                }
                if ($rule === 'max' && is_string($value) && strlen($value) > $limit) {
                    $valid = false;
                }
            }

            if (! $valid) {
                $errors[] = $field->name;
            }
        }

        if ($this->key === 's3' && (($configuration['key'] ?? '') !== '') !== (($configuration['secret'] ?? '') !== '')) {
            $errors[] = 'key/secret';
        }

        if ($errors !== []) {
            throw InvalidStorageConfigurationException::forFields($this->key, array_values(array_unique($errors)));
        }
    }

    public function mergePreservingSecrets(array $submitted, array $existing): array
    {
        foreach ($this->fields as $field) {
            if ($field->secret && (! array_key_exists($field->name, $submitted) || $submitted[$field->name] === '')) {
                if (array_key_exists($field->name, $existing)) {
                    $submitted[$field->name] = $existing[$field->name];
                }
            }
        }

        return $submitted;
    }

    public function configurationFields(): array
    {
        return $this->fields;
    }

    public function fieldNames(): array
    {
        return array_map(static fn (ConfigurationField $field): string => $field->name, $this->fields);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'driver' => $this->driver,
            'description' => $this->description,
            'fields' => array_map(static fn (ConfigurationField $field): array => $field->toArray(), $this->fields),
            'authorization' => $this->authorization,
            'capabilities' => [
                'provider' => $this->capabilities->provider,
                'supports_public_url' => $this->capabilities->supportsPublicUrl,
                'supports_temporary_url' => $this->capabilities->supportsTemporaryUrl,
                'supports_physical_path' => $this->capabilities->supportsPhysicalPath,
                'supports_stream_read' => $this->capabilities->supportsStreamRead,
                'supports_stream_write' => $this->capabilities->supportsStreamWrite,
                'supports_directories' => $this->capabilities->supportsDirectories,
                'supports_atomic_move' => $this->capabilities->supportsAtomicMove,
                'supports_server_side_copy' => $this->capabilities->supportsServerSideCopy,
                'max_file_size' => $this->capabilities->maxFileSize,
                'chunk_size' => $this->capabilities->chunkSize,
                'retry_attempts' => $this->capabilities->retryAttempts,
            ],
        ];
    }
}
