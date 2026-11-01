<?php

namespace Proclame\ClubPlanner;

class Redactor
{
    /**
     * Credentials and bank details. A member's IBAN comes back as "bankAccount".
     */
    public const SENSITIVE_FIELDS = [
        'password',
        'passwordhash',
        'pwd',
        'salt',
        'passwordsalt',
        'bankaccount',
        'bankaccountnumber',
        'accountnbr',
        'accountnumber',
        'iban',
        'bic',
    ];

    /**
     * Normalised keys that must never be returned to a caller.
     *
     * @var array<int, string>
     */
    protected array $keys;

    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(array $keys = [])
    {
        $this->keys = array_map($this->normalise(...), $keys);
    }

    /**
     * Recursively strip sensitive keys from an API payload.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function redact(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->shouldRedact($key)) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    public function shouldRedact(string $key): bool
    {
        return in_array($this->normalise($key), $this->keys, strict: true);
    }

    /**
     * Lower-case the key and drop separators, so "BankAccount", "bank_account"
     * and "bank account" all collapse onto the same entry.
     */
    protected function normalise(string $key): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
    }
}
