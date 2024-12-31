<?php

if (!function_exists('base_path')) {
    function base_path($path = '')
    {
        return realpath(__DIR__ . '/../') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path($path = '')
    {
        return base_path('storage') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('temp_path')) {
    function temp_path($path = '')
    {
        return base_path('temp') . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}