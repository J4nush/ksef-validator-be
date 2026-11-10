<?php

declare(strict_types=1);

use KsefFa3\Hash\Sha256Base64Url;

covers(Sha256Base64Url::class);

it('matches the reference vector', function () {
    expect(Sha256Base64Url::of('<root>test</root>'))->toBe('qrs2gCLXnTUy6--AHwHuDxFuCj0BYUGRGtBuGnJwN-c');
});

it('encodes the binary digest as unpadded Base64URL', function () {
    mt_srand(20261110);

    for ($i = 0; $i < 200; $i++) {
        $bytes = '';
        $length = mt_rand(0, 512);

        for ($j = 0; $j < $length; $j++) {
            $bytes .= chr(mt_rand(0, 255));
        }

        $hash = Sha256Base64Url::of($bytes);

        expect($hash)->toHaveLength(43)
            ->toMatch('/^[A-Za-z0-9_-]{43}$/D')
            ->and(base64_decode(strtr($hash, '-_', '+/'), true))->toBe(hash('sha256', $bytes, true));
    }
});
