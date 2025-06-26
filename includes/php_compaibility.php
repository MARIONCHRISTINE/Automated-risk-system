<?php
/**
 * PHP Compatibility Helper Functions
 * For supporting older PHP versions (7.x) with XAMPP
 */

// str_ends_with() function for PHP < 8.0
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }
}

// str_starts_with() function for PHP < 8.0
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// str_contains() function for PHP < 8.0
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

/**
 * Check PHP version and display warning if too old
 */
function checkPHPVersion() {
    $minVersion = '7.0';
    $currentVersion = phpversion();
    
    if (version_compare($currentVersion, $minVersion, '<')) {
        return [
            'compatible' => false,
            'message' => "Warning: PHP {$currentVersion} detected. Minimum required: {$minVersion}"
        ];
    }
    
    return [
        'compatible' => true,
        'message' => "PHP {$currentVersion} - Compatible ✓"
    ];
}

/**
 * Safe JSON decode with error handling
 */
function safe_json_decode($json, $assoc = false) {
    $data = json_decode($json, $assoc);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }
    
    return $data;
}

/**
 * Safe file operations
 */
function safe_file_get_contents($filename) {
    if (!file_exists($filename)) {
        return false;
    }
    
    return file_get_contents($filename);
}

/**
 * Helper function to check if string ends with another string
 * Compatible with older PHP versions
 */
function endsWith($haystack, $needle) {
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }
    return (substr($haystack, -$length) === $needle);
}

/**
 * Helper function to check if string starts with another string
 * Compatible with older PHP versions
 */
function startsWith($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}
?>
