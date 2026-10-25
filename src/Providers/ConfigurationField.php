<?php

declare(strict_types=1);

namespace SchoolPalm\StorageApi\Providers;

final readonly class ConfigurationField
{
    private const UNSET = "\0__storage_field_unset__\0";

    public string $name;

    public string $label;

    public string $type;

    public bool $required;

    public ?string $description;

    public ?string $placeholder;

    public mixed $default;

    public bool $secret;

    public array $options;

    public array $rules;

    public function __construct(
        string $name,
        string $label = '',
        string $type = 'text',
        bool $required = true,
        ?string $placeholder = null,
        ?string $description = null,
        mixed $default = null,
        array $options = [],
        bool $secret = false,
        array $rules = [],
    ) {
        $this->name = $name;
        $this->label = $label !== '' ? $label : self::makeLabel($name);
        $this->type = $secret && $type === 'text' ? 'password' : $type;
        $this->required = $required;
        $this->description = $description;
        $this->placeholder = $placeholder;
        $this->default = $default;
        $this->secret = $secret || self::sensitiveName($name);
        $this->options = $options;
        $this->rules = $rules;
    }

    public static function string(string $name): self
    {
        $secret = self::sensitiveName($name);

        return new self($name, self::makeLabel($name), $secret ? 'password' : 'text', secret: $secret);
    }

    public static function boolean(string $name): self
    {
        return new self($name, self::makeLabel($name), 'boolean', false, default: false);
    }

    public static function url(string $name): self
    {
        return new self($name, self::makeLabel($name), 'url');
    }

    public static function number(string $name): self
    {
        return new self($name, self::makeLabel($name), 'number');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(?string $value = null): string|self
    {
        return $value === null ? $this->label : $this->with(['label' => $value]);
    }

    public function type(?string $value = null): string|self
    {
        return $value === null ? $this->type : $this->with(['type' => $value]);
    }

    public function required(?bool $value = null): bool|self
    {
        return $value === null ? $this->required : $this->with(['required' => $value]);
    }

    public function secret(?bool $value = null): bool|self
    {
        return $value === null
            ? $this->secret
            : $this->with(['secret' => $value, 'type' => $value ? 'password' : $this->type]);
    }

    public function description(?string $value = self::UNSET): string|null|self
    {
        return $value === self::UNSET
            ? $this->description
            : $this->with(['description' => $value]);
    }

    public function placeholder(?string $value = self::UNSET): string|null|self
    {
        return $value === self::UNSET
            ? $this->placeholder
            : $this->with(['placeholder' => $value]);
    }

    public function default(mixed $value = self::UNSET): mixed
    {
        return $value === self::UNSET ? $this->default : $this->with(['default' => $value]);
    }

    public function options(?array $value = null): array|self
    {
        return $value === null ? $this->options : $this->with(['options' => $value]);
    }

    public function rules(array $value): self
    {
        return $this->with(['rules' => $value]);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'description' => $this->description,
            'placeholder' => $this->placeholder,
            'default' => $this->secret ? null : $this->default,
            'secret' => $this->secret,
            'options' => $this->options,
            'rules' => $this->rules,
        ];
    }

    private function with(array $changes): self
    {
        return new self(
            name: $this->name,
            label: $changes['label'] ?? $this->label,
            type: $changes['type'] ?? $this->type,
            required: $changes['required'] ?? $this->required,
            description: array_key_exists('description', $changes) ? $changes['description'] : $this->description,
            placeholder: array_key_exists('placeholder', $changes) ? $changes['placeholder'] : $this->placeholder,
            default: array_key_exists('default', $changes) ? $changes['default'] : $this->default,
            secret: $changes['secret'] ?? $this->secret,
            options: $changes['options'] ?? $this->options,
            rules: $changes['rules'] ?? $this->rules,
        );
    }

    private static function makeLabel(string $name): string
    {
        return ucfirst(preg_replace('/(?<=[a-z])(?=[A-Z])|[_-]+/', ' ', $name) ?? $name);
    }

    private static function sensitiveName(string $name): bool
    {
        $sensitive = preg_match('/(password|secret|token|access.?key)/i', $name) === 1;
        $expiry = preg_match('/(expires?|expiry|expiration)(at)?$/i', $name) === 1;

        return $sensitive && ! $expiry;
    }
}
