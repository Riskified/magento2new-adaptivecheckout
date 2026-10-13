<?php
declare(strict_types=1);

namespace Riskified\AdaptiveCheckout\Model\Security;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Quote\Api\Data\CartInterface;
use RuntimeException;

class CartStateHasher
{
    private const string KEY_DERIVATION_LABEL = 'Riskified_Advice:cart-state-hmac:v1';

    private ?string $derivedKey = null;

    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    public function emailHash(string $email, int|string $quoteId): string
    {
        return $this->hmac($email . '|' . $quoteId);
    }

    public function cartStateHash(CartInterface $quote, ?string $email): string
    {
        return $this->hmac($this->serializeCart($quote, $email));
    }

    public function cartToken(string $sessionId): string
    {
        return $this->hmac('cart_token|' . $sessionId);
    }

    private function serializeCart(CartInterface $quote, ?string $email): string
    {
        $cartData = [
            'quote_id'        => (int) $quote->getId(),
            'email'           => (string) $email,
            'currency'        => (string) $quote->getQuoteCurrencyCode(),
            'subtotal'        => $this->num($quote->getSubtotal()),
            'grand_total'     => $this->num($quote->getGrandTotal()),
            'tax_amount'      => $this->num($quote->getTaxAmount()),
            'discount_amount' => $this->num($quote->getDiscountAmount()),
            'items_count'     => (int) $quote->getItemsCount(),
            'items_qty'       => $this->num($quote->getItemsQty()),
        ];

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'item_id'    => (int) $item->getItemId(),
                'product_id' => (int) $item->getProductId(),
                'sku'        => (string) $item->getSku(),
                'qty'        => $this->num($item->getQty()),
                'price'      => $this->num($item->getPrice()),
                'row_total'  => $this->num($item->getRowTotal()),
            ];
        }
        // Stable ordering — getAllVisibleItems() iterates the items collection,
        // whose order can vary between a freshly-loaded quote and one that's
        // been mutated in memory.
        usort($items, static fn($a, $b) => $a['item_id'] <=> $b['item_id']);
        $cartData['items'] = $items;

        if ($shipping = $quote->getShippingAddress()) {
            $cartData['shipping_address'] = $this->serializeAddress($shipping);
        }
        if ($billing = $quote->getBillingAddress()) {
            $cartData['billing_address'] = $this->serializeAddress($billing);
        }

        return (string) json_encode($cartData, JSON_UNESCAPED_SLASHES);
    }

    private function serializeAddress($addr): array
    {
        $street = $addr->getStreet();
        if (is_array($street)) {
            $street = implode("\n", $street);
        }

        $region = $addr->getRegion();
        if (is_object($region) && method_exists($region, 'getRegion')) {
            $region = $region->getRegion();
        }

        return [
            'firstname'  => (string) $addr->getFirstname(),
            'lastname'   => (string) $addr->getLastname(),
            'street'     => (string) $street,
            'city'       => (string) $addr->getCity(),
            'region'     => (string) $region,
            'postcode'   => (string) $addr->getPostcode(),
            'country_id' => (string) $addr->getCountryId(),
        ];
    }

    private function num($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, $this->derivedKey());
    }

    private function derivedKey(): string
    {
        if ($this->derivedKey !== null) {
            return $this->derivedKey;
        }

        $rootSecret = $this->currentCryptKey();
        if ($rootSecret === '') {
            throw new RuntimeException(
                "Magento's crypt/key must be configured before computing advice integrity hashes."
            );
        }

        return $this->derivedKey = hash_hmac('sha256', self::KEY_DERIVATION_LABEL, $rootSecret, true);
    }

    private function currentCryptKey(): string
    {
        $configured = trim((string) $this->deploymentConfig->get(Encryptor::PARAM_CRYPT_KEY));
        if ($configured === '') {
            return '';
        }

        $keys = preg_split('/\s+/s', $configured) ?: [];

        return (string) end($keys);
    }
}
