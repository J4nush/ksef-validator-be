<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ProblemResponse
{
    public static function from(Throwable $exception): JsonResponse
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        [$slug, $title, $detail] = self::describe($status);

        return new JsonResponse(
            ['type' => '/api/problems/'.$slug, 'title' => $title, 'status' => $status, 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json', ...$headers],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return array{string, string, string}
     */
    private static function describe(int $status): array
    {
        return match (true) {
            $status === 404 => ['not-found', 'Nie znaleziono', 'Pod tym adresem nie ma zasobu API.'],
            $status === 405 => ['method-not-allowed', 'Niedozwolona metoda', 'Ten adres nie obsługuje tej metody HTTP.'],
            $status === 413 => ['payload-too-large', 'Plik jest za duży', 'Plik może mieć najwyżej 3 000 000 bajtów.'],
            $status === 415 => ['unsupported-media-type', 'Nieobsługiwany typ pliku', 'Wyślij plik XML z nagłówkiem Content-Type application/xml lub text/xml.'],
            $status === 429 => ['too-many-requests', 'Za dużo sprawdzeń', 'Przekroczono limit sprawdzeń z tego adresu. Spróbuj ponownie później.'],
            $status >= 500 => ['internal-error', 'Błąd serwera', 'Nie udało się sprawdzić pliku. Spróbuj ponownie za chwilę.'],
            default => ['bad-request', 'Nieprawidłowe żądanie', 'Serwer nie może obsłużyć tego żądania.'],
        };
    }
}
