# 0003: odrzucenie DOCTYPE przed parserem

## Kontekst

Faktury trafiają do walidatora od nieznanych użytkowników, więc plik XML traktujemy jak wrogie wejście. DOCTYPE jest potrzebny do ataków XXE i rozrastających się encji (billion laughs). Faktura KSeF go nie używa.

libxml2 w obrazie, na którym pakiet jest testowany (2.9.14 z Debiana trixie), ma kilka otwartych podatności bez poprawki. Najgroźniejsza z nich, CVE-2026-6653 (use after free w parsowaniu wewnętrznego podzbioru DTD), jest osiągalna tylko wtedy, gdy parser zobaczy DOCTYPE. Pozostałe dotyczą DTD, XInclude, XPointer i katalogów XML, czyli funkcji, których pakiet nie włącza.

## Decyzja

Zanim bajty trafią do libxml, skan bajtów sprawdza kolejno:

- czy plik nie jest pusty i nie przekracza 3 000 000 bajtów,
- czy jest poprawnym UTF-8 (UTF-16, UTF-32 i inne kodowania są odrzucane, także przez BOM),
- czy prolog nie deklaruje innego kodowania,
- czy nigdzie nie występuje `<!DOCTYPE`, bez rozróżniania wielkości liter.

Skan działa na surowych bajtach, więc nie da się go obejść kodowaniem, które parser rozpoznałby inaczej niż skan. Dlatego kodowania inne niż UTF-8 odrzucamy zanim zaczniemy szukać DOCTYPE. Dopiero po tym kroku plik idzie do parsera, zawsze z `LIBXML_NONET` i bez DTD, XInclude i katalogów XML.

## Skutki

Podatności libxml2, które wymagają DOCTYPE, DTD, XInclude, XPointer lub katalogów, są w tym użyciu nieosiągalne, nawet dopóki Debian nie wyda poprawek. Skaner obrazu w CI blokuje tylko podatności, dla których jest już poprawka. Te CVE libxml2 są dodatkowo wypisane w `.trivyignore` z opisem, dlaczego są nieosiągalne, i znikną z listy po aktualizacji libxml2.

Pliki z DOCTYPE są odrzucane także wtedy, gdy byłyby niegroźne. Przyjmujemy to, bo faktura KSeF nie ma powodu go zawierać. Błąd `SEC-DOCTYPE` mówi użytkownikowi, co usunąć.

Zabezpieczenie opiera się na założeniach, więc pilnują ich testy: XXE, billion laughs, DOCTYPE w każdym wariancie zapisu, UTF-16, UTF-7, głębokie drzewo, plik o rozmiarze limit plus jeden bajt i losowe bajty.
