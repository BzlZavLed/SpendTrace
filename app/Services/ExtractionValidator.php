<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExtractionValidator
{
    public static function schema(): array
    {
        $string = ['type' => ['string', 'null']];
        $decimal = ['type' => ['string', 'null'], 'description' => 'Printed amount in major units as an exact decimal string. Preserve its decimal places; use a period and no grouping separators. Null only if unreadable.'];
        $properties = array_fill_keys(['merchant', 'purchase_date', 'purchase_time', 'card_last_four', 'category'], $string);
        $properties['currency'] = ['type' => ['string', 'null'], 'enum' => [...array_keys(config('spendtrace.currencies')), null]];
        foreach (['total_decimal', 'subtotal_decimal', 'tax_decimal', 'tip_decimal', 'discount_decimal'] as $key) {
            $properties[$key] = $decimal;
        }
        $properties['printed_item_count'] = ['type' => ['integer', 'null']];
        $properties['arithmetic_complete'] = ['type' => 'boolean'];
        $properties['review_reasons'] = ['type' => 'array', 'items' => ['type' => 'string']];
        $properties['items'] = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['description' => $string, 'quantity' => $string, 'amount_decimal' => $decimal, 'category' => $string], 'required' => ['description', 'quantity', 'amount_decimal', 'category'], 'additionalProperties' => false]];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function validate(array $raw): array
    {
        if (is_string($raw['purchase_time'] ?? null) && preg_match('/^\d{2}:\d{2}$/D', $raw['purchase_time'])) {
            $raw['purchase_time'] .= ':00';
        }
        $rules = [
            'merchant' => 'present|nullable|string|max:200', 'purchase_date' => 'present|nullable|date_format:Y-m-d',
            'purchase_time' => 'present|nullable|date_format:H:i:s', 'currency' => ['present', 'nullable', Rule::in(array_keys(config('spendtrace.currencies')))],
            'card_last_four' => ['present', 'nullable', 'regex:/^\d{4}$/D'], 'category' => ['present', 'nullable', Rule::in(config('spendtrace.categories'))],
            'arithmetic_complete' => 'required|boolean', 'review_reasons' => 'present|array|max:30', 'review_reasons.*' => 'string|max:300',
            'printed_item_count' => 'sometimes|nullable|integer|min:0|max:10000',
            'items' => 'present|array|max:200', 'items.*' => 'array:description,quantity,amount_minor,amount_decimal,category',
            'items.*.description' => 'present|nullable|string|max:300', 'items.*.quantity' => ['present', 'nullable', 'regex:/^\d{1,8}(?:\.\d{1,3})?$/D'],
            'items.*.amount_minor' => 'sometimes|nullable|integer|between:-999999999999,999999999999',
            'items.*.amount_decimal' => ['sometimes', 'nullable', 'string', 'regex:/^-?\d{1,9}(?:\.\d{1,6})?$/D'],
            'items.*.category' => ['present', 'nullable', Rule::in(config('spendtrace.categories'))],
        ];
        foreach (['total_minor', 'subtotal_minor', 'tax_minor', 'tip_minor', 'discount_minor'] as $field) {
            $rules[$field] = 'sometimes|nullable|integer|between:-999999999999,999999999999';
            $rules[str_replace('_minor', '_decimal', $field)] = ['sometimes', 'nullable', 'string', 'regex:/^-?\d{1,9}(?:\.\d{1,6})?$/D'];
        }
        $data = Validator::make($raw, $rules)->validate();
        foreach (['total_minor', 'subtotal_minor', 'tax_minor', 'tip_minor', 'discount_minor'] as $field) {
            if (isset($data[$field]) && ! is_int($data[$field])) {
                throw new \RuntimeException('Invalid integer output.');
            }
        }
        foreach (['total', 'subtotal', 'tax', 'tip', 'discount'] as $name) {
            $data[$name.'_decimal'] = $data[$name.'_decimal'] ?? Money::format($data[$name.'_minor'] ?? null, $data['currency']);
            $data[$name.'_minor'] ??= null;
        }
        foreach ($data['items'] as &$item) {
            $item['amount_decimal'] = $item['amount_decimal'] ?? Money::format($item['amount_minor'] ?? null, $data['currency']);
            $item['amount_minor'] ??= null;
        }
        unset($item);
        try {
            foreach (['total', 'subtotal', 'tax', 'tip', 'discount'] as $name) {
                if ($data[$name.'_decimal'] !== null) {
                    $data[$name.'_minor'] = Money::parse($data[$name.'_decimal'], $data['currency'], $name.'_decimal');
                }
            }
            foreach ($data['items'] as $index => &$item) {
                if ($item['amount_decimal'] !== null) {
                    $item['amount_minor'] = Money::parse($item['amount_decimal'], $data['currency'], "items.{$index}.amount_decimal");
                }
            }
            unset($item);
        } catch (ValidationException $exception) {
            unset($item);
            $data['currency'] = null;
            $data['review_reasons'][] = 'Printed amounts have decimal precision incompatible with the suggested currency. Amounts are preserved; confirm the currency.';
            foreach (['total', 'subtotal', 'tax', 'tip', 'discount'] as $name) {
                $data[$name.'_minor'] = null;
            }
            foreach ($data['items'] as &$item) {
                $item['amount_minor'] = null;
            }
            unset($item);
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
        if (count($data['items']) === 0) {
            $reasons[] = 'No item lines were extracted. Compare the item section with the original receipt.';
        }
        $printedCount = $data['printed_item_count'] ?? null;
        if ($printedCount !== null && $printedCount > count($data['items'])) {
            $reasons[] = "Receipt reports {$printedCount} items sold, but only ".count($data['items']).' item lines were extracted. Check for missing lines; the printed count may include multiple units per line.';
        }
        foreach (['merchant', 'purchase_date', 'total_minor', 'currency'] as $field) {
            if ($data[$field] === null && ! ($field === 'total_minor' && ($data['total_decimal'] ?? null) !== null)) {
                $reasons[] = 'Missing '.str_replace('_', ' ', $field).'.';
            }
        }
        if (! $data['arithmetic_complete']) {
            $reasons[] = 'Receipt arithmetic could not be fully checked.';
        } else {
            $amounts = [];
            foreach (['subtotal', 'tax', 'tip', 'discount', 'total'] as $name) {
                $decimal = $data[$name.'_decimal'] ?? Money::format($data[$name.'_minor'] ?? null, $data['currency']);
                $amounts[$name] = $decimal !== null ? Money::scaled($decimal) : null;
            }
            if (in_array(null, $amounts, true)) {
                $reasons[] = 'Incomplete amounts for arithmetic check.';
            } elseif ($amounts['total'] !== $amounts['subtotal'] + $amounts['tax'] + $amounts['tip'] - $amounts['discount']) {
                $reasons[] = 'Subtotal, tax, tip, and discounts do not match the total.';
            }
        }

        return array_values(array_unique($reasons));
    }
}
