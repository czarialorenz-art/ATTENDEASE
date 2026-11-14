<?php

namespace App\Support;

use Illuminate\Support\Str;

final class FileName
{
    /** Strip spaces and characters not safe in file names. */
    public static function safe(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9.\-]/', '', Str::ascii($value)) ?: 'NA';
    }
}
