<?php

namespace App\Helpers;

use Stevebauman\Purify\Facades\Purify;

class HtmlSanitizer
{
    public static function clear(string $string, $config = null)
    {
        return Purify::clean($string, $config ?? [
            'HTML.Allowed' => 'p,b,i,em,strong,blockquote,a[href],ul,ol,li,h1,h2,h3,h4'
        ]);
    }
}
