# 0001: rdzeń bez frameworka

## Kontekst

Walidator ma działać w trzech miejscach: jako biblioteka w dowolnej aplikacji PHP, jako polecenie w terminalu i jako część API opartego na Laravelu. Gdyby logika sprawdzania faktur znała Laravela, pozostałe dwa sposoby użycia ciągnęłyby za sobą cały framework, a testy wymagałyby uruchamiania aplikacji.

## Decyzja

Pakiet nie zależy od żadnego frameworka. Zależności produkcyjne to rozszerzenia `dom`, `libxml`, `mbstring` oraz `symfony/yaml` do wczytania katalogu reguł.

Wszystko, co zmienia się w zależności od środowiska, jest za małym interfejsem:

- `Clock` dostarcza bieżącą datę (reguła daty wystawienia porównuje ją z dniem w strefie Europe/Warsaw),
- `SchemaRepository` wskazuje, skąd pochodzą schematy XSD.

Wejściem jest łańcuch bajtów, wyjściem obiekt `Report`. Pakiet sam nie czyta plików, nie zna HTTP ani konfiguracji. Czyta je cienka warstwa na zewnątrz: polecenie CLI w pakiecie albo kontroler w aplikacji. Test architektury pilnuje, żeby `src/` nie sięgał po klasy frameworków.

## Skutki

Testy jednostkowe są szybkie i nie potrzebują kontenera aplikacji. Zegar w testach jest stały, więc wyniki nie zależą od dnia uruchomienia.

Pakiet można zainstalować w projekcie na innym frameworku albo bez żadnego. Warstwa HTTP musi sama zadbać o limity żądań, typy treści i uwierzytelnianie, bo rdzeń ich nie obsługuje.

Koszt: kilka rzeczy, które framework dałby za darmo (konfiguracja, logowanie), trzeba przekazywać ręcznie. Rdzeń niczego nie loguje, więc w praktyce nie ma tu czego przekazywać.
