<?php

namespace Fleetbase\RegistryBridge\Support;

/**
 * Identifies who purchased (or may purchase) an extension.
 *
 * A purchaser is either a cloud Company or a RegistryDeveloperAccount. Purchases are stored
 * polymorphically on `registry_extension_purchases` via `purchaser_type` / `purchaser_uuid`,
 * with `company_uuid` kept for legacy company purchases.
 */
final class Purchaser
{
    public const TYPE_COMPANY           = 'Fleetbase\\Models\\Company';
    public const TYPE_DEVELOPER_ACCOUNT = 'Fleetbase\\RegistryBridge\\Models\\RegistryDeveloperAccount';

    private string $type;
    private string $uuid;

    private function __construct(string $type, string $uuid)
    {
        $this->type = $type;
        $this->uuid = $uuid;
    }

    public static function company(string $uuid): self
    {
        return new self(self::TYPE_COMPANY, $uuid);
    }

    public static function developerAccount(string $uuid): self
    {
        return new self(self::TYPE_DEVELOPER_ACCOUNT, $uuid);
    }

    /**
     * Build a purchaser from a stored purchaser type and uuid (e.g. Stripe metadata or a purchase row).
     *
     * Returns null when the type is not a known purchaser type or the uuid is missing.
     */
    public static function fromType(?string $type, ?string $uuid): ?self
    {
        if (empty($uuid) || !in_array($type, [self::TYPE_COMPANY, self::TYPE_DEVELOPER_ACCOUNT], true)) {
            return null;
        }

        return new self($type, $uuid);
    }

    /**
     * Resolve the purchaser for a registry account.
     *
     * Developer accounts purchase as their RegistryDeveloperAccount; cloud accounts (and legacy
     * rows with no account type) purchase as their Company.
     */
    public static function fromAccount(?string $accountType, ?string $companyUuid, ?string $developerAccountUuid): ?self
    {
        if ($accountType === 'developer') {
            return empty($developerAccountUuid) ? null : self::developerAccount($developerAccountUuid);
        }

        return empty($companyUuid) ? null : self::company($companyUuid);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function isCompany(): bool
    {
        return $this->type === self::TYPE_COMPANY;
    }

    public function isDeveloperAccount(): bool
    {
        return $this->type === self::TYPE_DEVELOPER_ACCOUNT;
    }

    /**
     * Column values identifying this purchaser on a purchase row.
     *
     * @return array{purchaser_type: string, purchaser_uuid: string}
     */
    public function toArray(): array
    {
        return [
            'purchaser_type' => $this->type,
            'purchaser_uuid' => $this->uuid,
        ];
    }
}
