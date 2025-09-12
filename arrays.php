<?php

class ArrayLib
{
    /**
     * Advanced array flattening with multiple options
     * 
     * @param array $array The array to flatten
     * @param array $options Configuration options
     * @return array Flattened array
     */
    public static function flatten($array, $options = [])
    {
        $defaults = [
            'separator' => '.',
            'prefix' => '',
            'preserve_keys' => true,
            'max_depth' => null,
            'skip_empty' => false,
            'key_transform' => null,
            'value_transform' => null,
            'filter_callback' => null,
            'merge_numeric_keys' => false,
            'include_path' => false,
        ];

        $config = array_merge($defaults, $options);

        return self::flattenRecursive($array, $config, $config['prefix'], 0);
    }

    private static function flattenRecursive($array, $config, $prefix, $depth)
    {
        $result = [];

        // Check max depth
        if ($config['max_depth'] !== null && $depth >= $config['max_depth']) {
            return [$prefix => $array];
        }

        foreach ($array as $key => $value) {
            // Apply key transformation if provided
            if ($config['key_transform'] && is_callable($config['key_transform'])) {
                $key = call_user_func($config['key_transform'], $key, $depth);
            }

            // Build new key
            if ($config['merge_numeric_keys'] && is_numeric($key)) {
                $newKey = $prefix;
            } else {
                $newKey = $prefix === '' ? $key : $prefix . $config['separator'] . $key;
            }

            // Skip empty values if configured
            if ($config['skip_empty'] && empty($value)) {
                continue;
            }

            // Apply filter callback if provided
            if ($config['filter_callback'] && is_callable($config['filter_callback'])) {
                if (!call_user_func($config['filter_callback'], $key, $value, $depth)) {
                    continue;
                }
            }

            if (is_array($value)) {
                // Recursively flatten nested arrays
                $nested = self::flattenRecursive($value, $config, $newKey, $depth + 1);
                $result = array_merge($result, $nested);
            } else {
                // Apply value transformation if provided
                if ($config['value_transform'] && is_callable($config['value_transform'])) {
                    $value = call_user_func($config['value_transform'], $value, $key, $depth);
                }

                // Include path metadata if requested
                if ($config['include_path']) {
                    $result[$newKey] = [
                        'value' => $value,
                        'path' => explode($config['separator'], $newKey),
                        'depth' => $depth
                    ];
                } else {
                    $result[$newKey] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Flatten only specific keys from nested arrays
     */
    public static function flattenSpecific($array, $keysToFlatten = [])
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && in_array($key, $keysToFlatten)) {
                $result = array_merge($result, $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Smart flatten - automatically detect arrays that should be flattened
     */
    public static function smartFlatten($array, $options = [])
    {
        $defaults = [
            'flatten_threshold' => 0.8,
            'max_items' => 50,
        ];

        $config = array_merge($defaults, $options);
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $shouldFlatten = self::shouldFlattenArray($value, $config);

                if ($shouldFlatten) {
                    $result = array_merge($result, $value);
                } else {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function shouldFlattenArray($array, $config)
    {
        $count = count($array);

        if ($count > $config['max_items']) {
            return false;
        }

        $nonArrayCount = 0;
        foreach ($array as $value) {
            if (!is_array($value)) {
                $nonArrayCount++;
            }
        }

        return ($nonArrayCount / $count) >= $config['flatten_threshold'];
    }

    /**
     * Flatten with grouping - group similar keys together
     */
    public static function flattenWithGrouping($array, $groupPatterns = [])
    {
        $result = [];
        $groups = [];

        $flattened = self::flatten($array);

        foreach ($flattened as $key => $value) {
            $grouped = false;

            foreach ($groupPatterns as $pattern => $groupName) {
                if (preg_match($pattern, $key)) {
                    if (!isset($groups[$groupName])) {
                        $groups[$groupName] = [];
                    }
                    $groups[$groupName][$key] = $value;
                    $grouped = true;
                    break;
                }
            }

            if (!$grouped) {
                $result[$key] = $value;
            }
        }

        return array_merge($result, $groups);
    }
}
