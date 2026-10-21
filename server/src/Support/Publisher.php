<?php

namespace Fleetbase\RegistryBridge\Support;

/**
 * Identifies who publishes (owns) an extension.
 *
 * Extensions record their owner via `publisher_type` ('cloud' or 'developer') and `publisher_uuid`
 * (a company uuid or a developer account uuid). Rows created before publisher fields existed only
 * carry `company_uuid`, which is treated as a cloud publisher.
 */
final class Publisher
{
    public const TYPE_CLOUD     = 'cloud';
    public const TYPE_DEVELOPER = 'developer';

    private string $type;
    private string $uuid;

    private function __construct(string $type, string $uuid)
    {
        $this->type = $type;
        $this->uuid = $uuid;
    }

    public static function cloud(string $companyUuid): self
    {
        return new self(self::TYPE_CLOUD, $companyUuid);
    }

    public static function developer(string $developerAccountUuid): self
    {
        return new self(self::TYPE_DEVELOPER, $developerAccountUuid);
    }

    /**
     * Resolve the publisher for a registry account.
     */
    public static function fromAccount(?string $accountType, ?string $companyUuid, ?string $developerAccountUuid): ?self
    {
        if ($accountType === self::TYPE_DEVELOPER) {
            return empty($developerAccountUuid) ? null : self::developer($developerAccountUuid);
        }

        return empty($companyUuid) ? null : self::cloud($companyUuid);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function isCloud(): bool
    {
        return $this->type === self::TYPE_CLOUD;
    }

    public function isDeveloper(): bool
    {
        return $this->type === self::TYPE_DEVELOPER;
    }

    /**
     * Determine whether this publisher owns an extension described by its publisher columns.
     *
     * When `publisher_uuid` is set it must match both type and uuid. Legacy rows without a
     * `publisher_uuid` fall back to `company_uuid`, which only a cloud publisher can match.
     */
    public function owns(?string $publisherType, ?string $publisherUuid, ?string $legacyCompanyUuid = null): bool
    {
        if (!empty($publisherUuid)) {
            return $publisherType === $this->type && $publisherUuid === $this->uuid;
        }

        return $this->isCloud() && !empty($legacyCompanyUuid) && $legacyCompanyUuid === $this->uuid;
    }

    /**
     * Column values identifying this publisher on an extension row.
     *
     * @return array{publisher_type: string, publisher_uuid: string}
     */
    public function toArray(): array
    {
        return [
            'publisher_type' => $this->type,
            'publisher_uuid' => $this->uuid,
        ];
    }
}
