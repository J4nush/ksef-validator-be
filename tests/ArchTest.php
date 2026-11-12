<?php

declare(strict_types=1);

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();

arch()->preset()->php();

arch('controllers leave XML parsing to the engine')
    ->expect(['DOMDocument', 'SimpleXMLElement', 'XMLReader', 'libxml_use_internal_errors', 'libxml_get_errors', 'libxml_set_external_entity_loader', 'simplexml_load_string'])
    ->not->toBeUsedIn('App\Http\Controllers');

arch('the application keeps no state')
    ->expect('App')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Session',
        'Illuminate\Support\Facades\Cookie',
        'Illuminate\Database',
        'Illuminate\Session',
        'Illuminate\Cookie',
    ]);

arch('the engine stays framework free')
    ->expect('KsefFa3')
    ->not->toUse(['Illuminate', 'Laravel', 'App']);
