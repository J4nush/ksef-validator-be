<?php

declare(strict_types=1);

namespace App\Http;

final class ClientKey
{
    public static function for(string $address): string
    {
        return hash_hmac('sha256', $address, config()->string('app.key'));
    }
}
