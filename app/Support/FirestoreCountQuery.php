<?php

namespace App\Support;

/**
 * Builds Firestore REST runAggregationQuery bodies that count documents.
 * Kept free of framework dependencies so it can be tested on its own.
 */
class FirestoreCountQuery
{
    public const COLLECTIONS = ['vendor_orders', 'parcel_orders', 'users', 'vendors', 'rides', 'rental_orders', 'provider_orders'];
    private const OPERATORS = [
        '==' => 'EQUAL', '!=' => 'NOT_EQUAL', '<' => 'LESS_THAN', '<=' => 'LESS_THAN_OR_EQUAL',
        '>' => 'GREATER_THAN', '>=' => 'GREATER_THAN_OR_EQUAL', 'in' => 'IN', 'array-contains' => 'ARRAY_CONTAINS',
    ];

    /** @throws \InvalidArgumentException */
    public static function body(array $query): array
    {
        $collection = $query['collection'] ?? '';
        if (!in_array($collection, self::COLLECTIONS, true)) {
            throw new \InvalidArgumentException('Collection not allowed: ' . $collection);
        }

        $filters = [];
        foreach ($query['where'] ?? [] as $where) {
            [$field, $op, $value] = array_pad((array) $where, 3, null);
            if (!is_string($field) || !isset(self::OPERATORS[$op])) {
                throw new \InvalidArgumentException('Invalid filter');
            }
            $filters[] = ['fieldFilter' => [
                'field' => ['fieldPath' => $field],
                'op' => self::OPERATORS[$op],
                'value' => self::encode($value),
            ]];
        }

        $structured = ['from' => [['collectionId' => $collection]]];
        if (count($filters) === 1) {
            $structured['where'] = $filters[0];
        } elseif (count($filters) > 1) {
            $structured['where'] = ['compositeFilter' => ['op' => 'AND', 'filters' => $filters]];
        }

        return ['structuredAggregationQuery' => [
            'structuredQuery' => $structured,
            'aggregations' => [['alias' => 'n', 'count' => (object) []]],
        ]];
    }

    /** Reads the count out of a runAggregationQuery response body. */
    public static function result(string $responseBody): int
    {
        $rows = json_decode($responseBody, true);
        return (int) ($rows[0]['result']['aggregateFields']['n']['integerValue'] ?? 0);
    }

    public static function encode($value): array
    {
        if (is_array($value) && array_key_exists('__timestamp', $value)) {
            $seconds = (int) $value['__timestamp'];
            $nanos = (int) ($value['nanos'] ?? 0);
            return ['timestampValue' => gmdate('Y-m-d\TH:i:s', $seconds) . '.' . sprintf('%09d', $nanos) . 'Z'];
        }
        if (is_array($value)) {
            return ['arrayValue' => ['values' => array_map([self::class, 'encode'], array_values($value))]];
        }
        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }
        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }
        if ($value === null) {
            return ['nullValue' => null];
        }
        return ['stringValue' => (string) $value];
    }
}
