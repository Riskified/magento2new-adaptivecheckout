<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Model;

final class AdviceResult
{
    public const int STATUS_SCA         = 0;
    public const int STATUS_PROCEED_TRA = 1;
    public const int STATUS_PROCEED     = 2;
    public const int STATUS_DECLINE     = 3;
    public const int STATUS_ERROR       = 9999;

    public function __construct(
        public readonly int $status,
        public readonly ?string $message = null,
    ) {}

    public function forceSca(): bool
    {
        return $this->status === self::STATUS_SCA;
    }

    public function isDeclined(): bool
    {
        return $this->status === self::STATUS_DECLINE;
    }

    /**
     * Allowlist check for a raw `riskified_advice_status` quote value:
     * proceed (with or without TRA) and SCA pass; SCA proceeds with forced 3DS.
     */
    public static function isProceedStatus(mixed $rawStatus): bool
    {
        if ($rawStatus === null || $rawStatus === '') {
            return false;
        }

        return in_array((int) $rawStatus, [self::STATUS_SCA, self::STATUS_PROCEED, self::STATUS_PROCEED_TRA], true);
    }
}
