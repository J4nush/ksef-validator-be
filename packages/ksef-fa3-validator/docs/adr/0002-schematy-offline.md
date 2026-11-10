# 0002: schematy offline

## Kontekst

Walidacja opiera się na schemacie XSD wydanym przez Ministerstwo Finansów. Główny plik `schemat_FA(3)_v1-0E.xsd` importuje pliki bazowe przez adres `schemaLocation` wskazujący na serwer w internecie. Domyślnie libxml pobrałby je przy każdej walidacji. To dodaje opóźnienie, zależność od cudzego serwera i kanał, którym złośliwy plik mógłby wymusić połączenie sieciowe.

## Decyzja

Schematy leżą w pakiecie, w `resources/schemas/`, i są zapisane bajt w bajt tak, jak je opublikowano. Nie edytujemy ich. Dzięki temu skrót SHA-256 pliku XSD można porównać z oryginałem, a `source.json` zapisuje commit źródłowego repozytorium MF i ten skrót. Obie wartości trafiają do każdego raportu.

Zamiast poprawiać pliki, zamieniamy `schemaLocation` w pamięci: w momencie walidacji adres zdalny jest podmieniany na ścieżkę lokalną. Parser i walidator zawsze dostają flagę `LIBXML_NONET`. Zewnętrzne zasoby można wczytać tylko z katalogu ze schematami.

Licencja MIT Ministerstwa Finansów jest dołączona obok schematów (`LICENSE-MF.txt`).

## Skutki

Walidacja działa bez sieci, także w kontenerze z wyłączonym dostępem do internetu. Wynik zależy tylko od pliku faktury i wersji pakietu.

Gdy MF opublikuje nowy schemat, trzeba wydać nową wersję pakietu. Zmiana jest widoczna w `source.json` i w polu `ruleset`. Prosta aktualizacja bez wydania nie jest możliwa, i taki jest zamiar.

Pliki XSD i pliki XML w testach są oznaczone w `.gitattributes` jako niepodlegające normalizacji końców linii, żeby skrót się nie zmienił po sklonowaniu na innym systemie.
