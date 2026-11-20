<?php

declare(strict_types=1);

namespace App\Application\Integration\ConfigureConnection;

use App\Application\Integration\ConnectionSecrets;
use App\Application\Integration\Exception\MissingSetting;
use App\Application\Integration\Exception\SecretNeededAgain;
use App\Application\Integration\ServiceDescription;
use App\Domain\Integration\ServiceConnection;

final readonly class ConnectionCredentials
{
    public function __construct(private ConnectionSecrets $secrets)
    {
    }

    /**
     * @param array<string, string|null> $fields
     */
    public function write(ServiceConnection $connection, ServiceDescription $description, array $fields): bool
    {
        $typed = [];
        foreach ($description->fields as $field) {
            $typed[$field->name] = $field->normalize((string) ($fields[$field->name] ?? ''));
        }
        $this->check($connection, $description, $typed);

        $settings = [];
        foreach ($description->plainFields() as $field) {
            $settings[$field->name] = $typed[$field->name];
        }
        $changed = $connection->configure($settings);
        foreach ($description->secretFields() as $field) {
            if ('' !== $typed[$field->name] && $typed[$field->name] !== $this->secrets->reveal($connection, $field->name)) {
                $this->secrets->keep($connection, $field->name, $typed[$field->name]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, string> $typed
     */
    private function check(ServiceConnection $connection, ServiceDescription $description, array $typed): void
    {
        $retargeted = self::retargeted($connection, $description, $typed);
        foreach ($description->fields as $field) {
            if ('' !== $typed[$field->name]) {
                continue;
            }
            $kept = $field->secret && null !== $this->secrets->reveal($connection, $field->name);
            if ($kept && $retargeted) {
                throw new SecretNeededAgain($field->name);
            }
            if ($field->required && !$kept) {
                throw new MissingSetting($field->name);
            }
        }
    }

    /**
     * @param array<string, string> $typed
     */
    private static function retargeted(ServiceConnection $connection, ServiceDescription $description, array $typed): bool
    {
        foreach ($description->plainFields() as $field) {
            if ($field->bindsSecrets && $typed[$field->name] !== ($connection->setting($field->name) ?? '')) {
                return true;
            }
        }

        return false;
    }
}
