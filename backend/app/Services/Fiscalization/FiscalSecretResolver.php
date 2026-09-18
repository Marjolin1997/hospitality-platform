<?php

namespace App\Services\Fiscalization;

use RuntimeException;

final class FiscalSecretResolver
{
    public function resolve(string $reference): string
    {
        [$scheme, $identifier] = array_pad(explode(':', $reference, 2), 2, null);

        if (! $scheme || ! $identifier) {
            throw new RuntimeException('Invalid fiscal secret reference.');
        }

        return match ($scheme) {
            'env' => $this->fromEnvironment($identifier),
            'secret' => $this->fromMountedSecret($identifier),
            'vault' => throw new RuntimeException('Vault fiscal secret resolution is not configured on this deployment.'),
            default => throw new RuntimeException('Unsupported fiscal secret reference scheme.'),
        };
    }

    private function fromEnvironment(string $name): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Invalid environment secret identifier.');
        }

        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException("Fiscal secret environment variable '{$name}' is not available.");
        }

        return $value;
    }

    private function fromMountedSecret(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new RuntimeException('Invalid mounted secret identifier.');
        }

        $base = rtrim((string) config('fiscalization.secret_dir'), '/');
        $path = $base.'/'.$name;

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Mounted fiscal secret '{$name}' is not available.");
        }

        $value = file_get_contents($path);
        if ($value === false || $value === '') {
            throw new RuntimeException("Mounted fiscal secret '{$name}' is empty.");
        }

        return $value;
    }
}
