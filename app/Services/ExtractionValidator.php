<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExtractionValidator
{
    public static function schema(): array
    {
        $string = ['type' => ['string', 'null']];
        $money = ['type' => ['integer', 'null']];
        $properties = array_fill_keys(['merchant', 'purchase_date', 'purchase_time', 'card_last_four', 'category'], $string);
        $properties['currency'] = ['type' => ['string', 'null'], 'enum' => [...array_keys(config('spendtrace.currencies')), null]];
        foreach (['total_minor', 'subtotal_minor', 'tax_minor', 'tip_minor', 'discount_minor'] as $key) {
            $properties[$key] = $money;
        }
        $properties['arithmetic_complete'] = ['type' => 'boolean'];
        $properties['review_reasons'] = ['type' => 'array', 'items' => ['type' => 'string']];
        $properties['items'] = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['description' => $string, 'quantity' => $string, 'amount_minor' => $money, 'category' => $string], 'required' => ['description', 'quantity', 'amount_minor', 'category'], 'additionalProperties' => false]];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function validate(array $raw): array
    {
        $rules = [
            'merchant' => 'present|nullable|string|max:200', 'purchase_date' => 'present|nullable|date_format:Y-m-d',
            'purchase_time' => 'present|nullable|date_format:H:i:s', 'currency' => ['present', 'nullable', Rule::in(array_keys(config('spendtrace.currencies')))],
            'card_last_four' => ['present', 'nullable', 'regex:/^\d{4}$/D'], 'category' => ['present', 'nullable', Rule::in(config('spendtrace.categories'))],
            'arithmetic_complete' => 'required|boolean', 'review_reasons' => 'present|array|max:30', 'review_reasons.*' => 'string|max:300',
            'items' => 'present|array|max:200', 'items.*' => 'array:description,quantity,amount_minor,category',
            'items.*.description' => 'present|nullable|string|max:300', 'items.*.quantity' => ['present', 'nullable', 'regex:/^\d{1,8}(?:\.\d{1,3})?$/D'],
            'items.*.amount_minor' => 'present|nullable|integer|between:-999999999999,999999999999',
            'items.*.category' => ['present', 'nullable', Rule::in(config('spendtrace.categories'))],
        ];
        foreach (['total_minor', 'subtotal_minor', 'tax_minor', 'tip_minor', 'discount_minor'] as $field) {
            $rules[$field] = 'present|nullable|integer|between:-999999999999,999999999999';
        }
        $data = Validator::make($raw, $rules)->validate();
        foreach (['total_minor', 'subtotal_minor', 'tax_minor', 'tip_minor', 'discount_minor'] as $field) {
            if ($data[$field] !== null && ! is_int($data[$field])) {
                throw new \RuntimeException('Invalid integer output.');
            }
        }
        array_walk_recursive($data, function (&$value): void {
            if (is_string($value)) {
                $value = preg_replace('/(?:\d[ -]?){12,19}/', '[redacted]', $value);
            }
        });

        return $data;
    }

    public function reasons(array $data): array
    {
        $reasons = $data['review_reasons'];
        foreach (['merchant', 'purchase_date', 'total_minor', 'currency'] as $field) {
            if ($data[$field] === null) {
                $reasons[] = 'Missing '.str_replace('_', ' ', $field).'.';
            }
        }
        if (! $data['arithmetic_complete']) {
            $reasons[] = 'Receipt arithmetic could not be fully checked.';
        } elseif (count(array_filter(array_intersect_key($data, array_flip(['subtotal_minor', 'tax_minor', 'tip_minor', 'discount_minor', 'total_minor'])), fn ($v) => $v === null))) {
            $reasons[] = 'Incomplete amounts for arithmetic check.';
        } elseif ($data['total_minor'] !== $data['subtotal_minor'] + $data['tax_minor'] + $data['tip_minor'] - $data['discount_minor']) {
            $reasons[] = 'Subtotal, tax, tip, and discounts do not match the total.';
        }

        return array_values(array_unique($reasons));
    }
}
