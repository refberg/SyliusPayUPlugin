# Sylius PayU Plugin

[English version](README.md)

Bramka płatności PayU dla Syliusa 2, oparta na systemie Payment Request Syliusa i PayU REST API v2.1.
Plugin nie używa Payum.

## Funkcje

- Płatność na stronie PayU (przekierowanie) w checkoucie sklepu i przez API Syliusa.
- Stan płatności zmienia tylko podpisane powiadomienie od PayU albo bezpośrednie zapytanie do API PayU.
  Powrót klienta z PayU nigdy nie jest traktowany jako potwierdzenie zapłaty.
- Powiadomienia są weryfikowane (`OpenPayu-Signature`). Następnie zamówienie jest ponownie pobierane z API PayU
  i porównywane z płatnością w Syliusie: identyfikator zamówienia PayU, `extOrderId`, POS ID, kwota i waluta.
- Duplikaty i powiadomienia w złej kolejności nie zmieniają wyniku i nigdy nie opłacają zamówienia dwa razy.
- Pełne zwroty z panelu admina.
- Dane dostępowe są przechowywane w postaci zaszyfrowanej przez Syliusa i nie trafiają do logów.

Poza zakresem: zapisane karty, płatności cykliczne, osadzony formularz karty, zwroty częściowe.

## Wymagania

| Sylius | PHP     |
|--------|---------|
| 2.2    | 8.2–8.4 |
| 2.3    | 8.3–8.4 |

## Instalacja

1. Zainstaluj pakiet:

   ```bash
   composer require refberg/sylius-payu-plugin
   ```

2. Zarejestruj bundle w `config/bundles.php`, jeśli Symfony Flex tego nie zrobił:

   ```php
   Refberg\SyliusPayUPlugin\RefbergSyliusPayUPlugin::class => ['all' => true],
   ```

3. Zaimportuj konfigurację w `config/packages/refberg_sylius_payu.yaml`:

   ```yaml
   imports:
       - { resource: "@RefbergSyliusPayUPlugin/config/config.yaml" }
   ```

4. Upewnij się, że istnieje klucz szyfrowania konfiguracji bramek (Sylius tworzy go podczas instalacji):

   ```bash
   bin/console sylius:payment:generate-key
   ```

Migracje bazy danych nie są potrzebne. Plugin trzyma swoje dane w `sylius_payment.details`.

## Konfiguracja

W panelu admina przejdź do **Konfiguracja → Metody płatności → Utwórz → PayU** i uzupełnij:

| Pole                        | Nazwa w panelu PayU               |
|-----------------------------|-----------------------------------|
| Środowisko                  | sandbox lub produkcja             |
| Id punktu płatności         | `pos_id`                          |
| Drugi klucz (MD5)           | drugi klucz (MD5)                 |
| OAuth – `client_id`         | protokół OAuth – `client_id`      |
| OAuth – `client_secret`     | protokół OAuth – `client_secret`  |

Drugi klucz i `client_secret` nie są ponownie wyświetlane po zapisaniu.
Zostaw te pola puste, aby zachować zapisane wartości.

Adres powiadomień jest przekazywany do PayU przy każdym zamówieniu, więc nie trzeba go ustawiać w panelu PayU.
Ma postać `https://<twój-sklep>/payment-methods/<KOD_METODY_PŁATNOŚCI>`.

W panelu PayU zostaw włączony **automatyczny odbiór płatności (autoReceive)** dla punktu płatności.
Zamówienia czekające na ręczne potwierdzenie (`WAITING_FOR_CONFIRMATION`) pozostają w Syliusie nieopłacone.

Jeśli żądania płatności są przetwarzane asynchronicznie (`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` inny niż `sync://`),
ustaw `framework.router.default_uri` na publiczny adres sklepu, żeby adres powiadomień był generowany poprawnie.

## Jak to działa

### Sklep

1. Klient składa zamówienie i klika **Zapłać**. Plugin tworzy zamówienie w PayU i przekierowuje klienta do PayU.
2. Po płatności klient wraca do sklepu (`/order/after-pay/{hash}`). Plugin pyta API PayU o status zamówienia.
   Parametry dodane przez PayU do adresu powrotu (np. `error=501`) są ignorowane.
   Dopóki PayU przetwarza płatność, klient widzi stronę podziękowania, a płatność pozostaje nieopłacona.
3. PayU wysyła powiadomienie na `/payment-methods/{code}`. Płatność zostaje opłacona albo oznaczona jako nieudana
   na podstawie stanu zamówienia pobranego z API PayU.

Ponowne otwarcie strony płatności używa zamówienia PayU, które jest jeszcze w toku, zamiast tworzyć nowe.

### API

Utwórz żądanie płatności dla złożonego zamówienia:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "PAYU",
    "payload": {"continue_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` zawiera adres strony płatności PayU. `continue_url` jest opcjonalne, jeśli sklep jest zainstalowany.
Bez niego klient wraca na stronę after-pay sklepu.

Po powrocie klienta zapytaj o status:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{"paymentId": 123, "paymentMethodCode": "PAYU", "action": "status"}
```

`responseData.payment_state` zawiera stan płatności w Syliusie, a `responseData.payu_status` status zamówienia w PayU.

### Reguły zmiany stanu płatności

| Status zamówienia PayU                       | Płatność w Syliusie                                                        |
|----------------------------------------------|----------------------------------------------------------------------------|
| `NEW`, `PENDING`, `WAITING_FOR_CONFIRMATION` | bez zmian (`new`)                                                          |
| `COMPLETED`                                  | `completed`, jednokrotnie                                                  |
| `CANCELED`                                   | `failed`, tylko gdy żadne inne zamówienie PayU tej płatności nie jest otwarte |

Opłacona płatność nigdy nie wraca do wcześniejszego stanu. Jeśli zostanie opłacone drugie zamówienie PayU tej
samej płatności (np. klient ponowił płatność w innej karcie), zamówienie w Syliusie nie zostanie opłacone dwa razy.
Plugin zapisuje ostrzeżenie w szczegółach płatności (`refberg_payu.warnings`) i loguje błąd na kanale `payu`.
Taką płatność trzeba zwrócić ręcznie w panelu PayU.

Powiadomienia bez podpisu lub z błędnym podpisem dostają odpowiedź HTTP 400 i nie są zapisywane. Powiadomienia
dotyczące nieznanych zamówień dostają HTTP 404. Gdy API PayU jest niedostępne, endpoint odpowiada HTTP 5xx,
więc PayU ponowi powiadomienie później.

### Zwroty

Kliknij **Zwrot** przy opłaconej płatności PayU w panelu admina. Plugin zleca w PayU pełny zwrot
(`extRefundId` to identyfikator żądania płatności, więc ponowienia nie tworzą duplikatów). Końcowy status zwrotu
z powiadomienia PayU jest zapisywany w szczegółach płatności.

Sylius oznacza płatność jako zwróconą od razu, zanim PayU potwierdzi zwrot. Jeśli PayU odrzuci zwrot, żądanie
zwrotu zostaje oznaczone jako nieudane i zapisywany jest błąd w logu. Wtedy zwrot trzeba obsłużyć w panelu PayU.

## Bezpieczeństwo

- Sekrety są przechowywane w konfiguracji bramki, którą Sylius szyfruje.
- Sekrety i tokeny OAuth nigdy nie trafiają do logów ani do danych żądań płatności.
  Komunikaty wyjątków zawierają tylko kody HTTP i kody statusu PayU.
- Token OAuth jest przechowywany w puli cache `refberg_payu.cache` (domyślnie `cache.app`) do chwili tuż przed wygaśnięciem.
- Ruch do PayU przechodzi przez serwis `refberg_payu.http_client` (alias `http_client`).
  Można go nadpisać, np. aby użyć proxy.

## Rozwój

```bash
composer install
vendor/bin/ecs check
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite=unit
```

Testy integracyjne uruchamiają aplikację testową Syliusa na MySQL:

```bash
docker run -d --name payu-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:8.0
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:schema:create
vendor/bin/phpunit --testsuite=integration
```

Testy nigdy nie łączą się z PayU. Wszystkie żądania trafiają do działającej w pamięci atrapy sandboxa PayU
(`tests/Support/FakePayU.php`), która odrzuca każdy inny host. Testy obejmują powiadomienia podpisane,
zduplikowane, w złej kolejności oraz sfałszowane.

## Licencja

MIT, zobacz [LICENSE](LICENSE).
