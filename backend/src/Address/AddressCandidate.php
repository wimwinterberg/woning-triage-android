<?php

declare(strict_types=1);

namespace App\Address;

final class AddressCandidate
{
    public function __construct(
        public readonly string $candidateId,
        public readonly string $postcode,
        public readonly int $houseNumber,
        public readonly ?string $addition,
        public readonly string $street,
        public readonly string $city,
        public readonly string $countryCode = 'NL',
        public readonly ?string $providerId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $display = trim($this->street.' '.$this->houseNumber.($this->addition ? ' '.$this->addition : '').', '.$this->postcode.' '.$this->city);

        return [
            'candidate_id' => $this->candidateId,
            'postcode' => $this->postcode,
            'house_number' => $this->houseNumber,
            'addition' => $this->addition,
            'street' => $this->street,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'display_address' => $display,
            'provider_id' => $this->providerId,
        ];
    }
}
