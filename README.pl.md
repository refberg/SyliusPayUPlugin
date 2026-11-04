# Sylius PayU Plugin

[![Build](https://github.com/refberg/SyliusPayUPlugin/actions/workflows/build.yaml/badge.svg?branch=main)](https://github.com/refberg/SyliusPayUPlugin/actions/workflows/build.yaml)
[![Latest version](https://img.shields.io/packagist/v/refberg/sylius-payu-plugin)](https://packagist.org/packages/refberg/sylius-payu-plugin)
[![Downloads](https://img.shields.io/packagist/dt/refberg/sylius-payu-plugin)](https://packagist.org/packages/refberg/sylius-payu-plugin)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](composer.json)
[![Sylius](https://img.shields.io/badge/Sylius-2.2%20%7C%202.3-1abb9c)](https://sylius.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208-000000)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

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
- Pełne zwroty z panelu admina; zwroty zlecone w panelu PayU są synchronizowane.
- Anulowanie płatności w Syliusie anuluje otwarte zamówienie w PayU.
- Dane dostępowe są przechowywane w postaci zaszyfrowanej przez Syliusa i nie trafiają do logów.

Poza zakresem: zapisane karty, płatności cykliczne, osadzony formularz karty, zwroty częściowe zlecane z Syliusa.

## Wymagania

| Sylius | PHP     | Symfony                                 |
|--------|---------|-----------------------------------------|
| 2.2.9+ | 8.2–8.4 | 6.4, 7.4                                |
| 2.3    | 8.3–8.5 | 6.4, 7.4, 8.x (8.x wymaga PHP 8.4+)     |

Sklep Syliusa (`SyliusShopBundle`) i panel admina (`SyliusAdminBundle`) są opcjonalne, więc plugin działa także
w aplikacjach udostępniających tylko API.

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

5. Tylko w aplikacjach bez sklepu Syliusa: zaimportuj trasę powiadomień o płatności, którą Sylius importuje
   zwykle razem z trasami sklepu, w `config/routes/sylius_payment.yaml`:

   ```yaml
   sylius_payment_notify:
       resource: "@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml"
   ```

   Bez tej trasy żądania capture kończą się błędem, a PayU nie może dostarczyć powiadomień. W aplikacji z panelem admina
   nie da się też wyświetlić strony edycji metody płatności PayU, bo pokazuje ona adres powiadomień.
   Klienci API muszą też przesyłać `continue_url` w payloadzie capture (zobacz [API](#api)).

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

PayU ponawia powiadomienie, dopóki nie dostanie odpowiedzi HTTP 200, maksymalnie przez 72 godziny. Jeśli firewall
filtruje ruch przychodzący, dopuść serwery powiadomień PayU:

- produkcja: 185.68.12.10, 185.68.12.11, 185.68.12.12, 185.68.12.26, 185.68.12.27, 185.68.12.28
- sandbox: 185.68.14.10, 185.68.14.11, 185.68.14.12, 185.68.14.26, 185.68.14.27, 185.68.14.28

Do ręcznych testów możesz użyć publicznego punktu płatności PLN w sandboxie, opisanego w
[dokumentacji sandboxa PayU](https://developers.payu.com/europe/docs/testing/sandbox/).

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
Bez niego klient wraca na stronę after-pay sklepu. Bez sklepu żądanie capture bez `continue_url` kończy się błędem,
a PayU nie jest wywoływane.

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

Jeśli klient po przekierowaniu do PayU zmieni w zamówieniu metodę płatności, zamówienie PayU utworzone przed zmianą
nadal należy do płatności i można je opłacić. Powiadomienie o jego opłaceniu opłaca płatność; plugin zapisuje ostrzeżenie
`paid_with_previous_payment_method` i loguje ostrzeżenie. Płatność ma już inną metodę, więc zwroty i anulowania tego
zamówienia z Syliusa mogą do niego nie trafić:

- Inna metoda PayU: zwroty i anulowania z Syliusa są wysyłane z danymi dostępowymi nowej metody. Dla zamówienia
  z innego POS PayU powinno je odrzucić; żądanie jest wtedy oznaczane jako nieudane, a błąd trafia do logu.
- Metoda spoza PayU: zwroty i anulowania w Syliusie nie trafiają do PayU. Anulowana płatność zostawia stare zamówienie
  PayU otwarte; jeśli klient je opłaci, płatność pozostaje anulowana i dostaje ostrzeżenie `payment_completed_after_failure`.

W obu przypadkach zwrot lub anulowanie trzeba zrobić w panelu PayU.

Powiadomienia bez podpisu lub z błędnym podpisem dostają odpowiedź HTTP 400 i nie są zapisywane. Powiadomienia
dotyczące nieznanych zamówień dostają HTTP 404. Gdy API PayU jest niedostępne albo odrzuca dane dostępowe pluginu
(HTTP 401/403), endpoint odpowiada HTTP 5xx, więc PayU ponowi powiadomienie później.

### Zwroty

Kliknij **Zwrot** przy opłaconej płatności PayU w panelu admina. Plugin zleca w PayU pełny zwrot
(`extRefundId` to identyfikator żądania płatności, więc ponownie dostarczone żądanie nie tworzy drugiego zwrotu).
Końcowy status zwrotu z powiadomienia PayU jest zapisywany w szczegółach płatności.

Zwroty zlecone wcześniej w panelu PayU (poza anulowanymi) są odejmowane od kwoty zwrotu. Gdy pokrywają całą
płatność, plugin nie wywołuje PayU i oznacza żądanie zwrotu jako zakończone.

Sylius oznacza płatność jako zwróconą od razu, zanim PayU potwierdzi zwrot. Jeśli PayU odrzuci zwrot, żądanie
zwrotu zostaje oznaczone jako nieudane i zapisywany jest błąd w logu. Wtedy zwrot trzeba obsłużyć w panelu PayU.
Sylius nie ponawia nieudanego żądania automatycznie, także gdy PayU było niedostępne (timeout, HTTP 5xx).

### Anulowanie

Gdy płatność PayU zostanie anulowana w Syliusie (np. razem z zamówieniem), plugin anuluje w PayU wszystkie
zamówienia tej płatności, które mogą jeszcze zostać opłacone (`DELETE /api/v2_1/orders/{orderId}`). Jeśli PayU
odmówi, bo zamówienie zostało już opłacone, żądanie anulowania jest oznaczane jako nieudane i zapisywany jest błąd
w logu. Późniejsze powiadomienie COMPLETED zostaje zapisane jako ostrzeżenie, a płatność nie zostaje opłacona.
Sylius nie ponawia nieudanego żądania anulowania automatycznie, także gdy PayU było niedostępne (timeout, HTTP 5xx);
sprawdź wtedy zamówienie w panelu PayU.

### Zwroty zlecone w panelu PayU

Podpisane powiadomienie o zwrocie, którego nie zlecił Sylius, jest zapisywane w szczegółach płatności.
Gdy zakończone zwroty pokryją całą kwotę płatności, płatność przechodzi w stan `refunded`, a do PayU nie jest
wysyłany kolejny zwrot. Zwroty częściowe są widoczne tylko w szczegółach płatności, bo Sylius nie ma dla nich stanu.

## Bezpieczeństwo

- Sekrety są przechowywane w konfiguracji bramki, którą Sylius szyfruje.
- Zwroty i anulowania trafiają do PayU tylko dla płatności już zwróconej lub anulowanej w Syliusie,
  więc nie da się ich wywołać przez shop API.
- Sekrety i tokeny OAuth nigdy nie trafiają do logów ani do danych żądań płatności.
  Komunikaty wyjątków zawierają tylko kody HTTP i kody statusu PayU.
- Token OAuth jest przechowywany w puli cache `refberg_payu.cache` (domyślnie `cache.app`) do chwili tuż przed wygaśnięciem.
- Ruch do PayU przechodzi przez serwis `refberg_payu.http_client` (alias `http_client`).
  Można go nadpisać, np. aby użyć proxy.

## Rozszerzanie

Poniższe serwisy są zarejestrowane pod swoim interfejsem. Dekoruj lub podmieniaj je po interfejsie:

| Identyfikator serwisu                                               | Odpowiedzialność                                     |
|---------------------------------------------------------------------|------------------------------------------------------|
| `Refberg\SyliusPayUPlugin\Api\AccessTokenProviderInterface`         | token OAuth do API PayU                              |
| `Refberg\SyliusPayUPlugin\Api\PayUClientInterface`                  | wywołania REST API PayU                              |
| `Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface`      | dane dostępowe PayU z konfiguracji bramki            |
| `Refberg\SyliusPayUPlugin\Signature\SignatureVerifierInterface`     | weryfikacja nagłówka `OpenPayu-Signature`            |
| `Refberg\SyliusPayUPlugin\Notification\NotificationParserInterface` | parsowanie podpisanych powiadomień                   |
| `Refberg\SyliusPayUPlugin\Order\OrderRequestFactoryInterface`       | treść żądania utworzenia zamówienia PayU             |
| `Refberg\SyliusPayUPlugin\Order\ExtOrderIdGeneratorInterface`       | wartości `extOrderId`                                |
| `Refberg\SyliusPayUPlugin\Order\PayUOrderValidatorInterface`        | zgodność zamówienia PayU z płatnością Sylius         |
| `Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface`           | blokada płatności                                    |
| `Refberg\SyliusPayUPlugin\Payment\PaymentStateApplierInterface`     | zmiana stanu płatności                               |
| `Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizerInterface`     | pobranie, sprawdzenie i zastosowanie zamówienia PayU |

Na przykład zmiana formatu `extOrderId`:

```yaml
services:
    App\Payment\ExtOrderIdGenerator:
        decorates: Refberg\SyliusPayUPlugin\Order\ExtOrderIdGeneratorInterface
        arguments: ['@.inner']
```

`extractPaymentId()` musi odczytać ID płatności z każdej wartości zwróconej przez `generate()`,
bo po nim powiadomienia są przypisywane do płatności.

## Rozwój

```bash
composer install
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/composer-dependency-analyser
APP_ENV=test vendor/bin/console cache:warmup   # PHPStan korzysta ze skompilowanego kontenera testowego
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite=unit
```

PHPStan działa na poziomie max z regułami strict, deprecation, Doctrine, Symfony i PHPUnit. CI sprawdza też
`composer normalize` i `composer audit` oraz uruchamia testy mutacyjne Infection (PHP 8.3+, `infection.json5`).

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

### Testy na sandboxie PayU

`tests/Sandbox` przechodzi cały proces na prawdziwym sandboxie PayU: płatność w sklepie, zapłatę na stronie PayU
(zaakceptowaną i odrzuconą, przez symulator dostawcy), sprawdzenie statusu, zwrot i anulowanie. Te testy nie są
częścią CI i są pomijane, dopóki ich nie włączysz. Dane dostępowe są czytane tylko ze zmiennych środowiskowych;
możesz użyć publicznego punktu płatności z [dokumentacji sandboxa PayU](https://developers.payu.com/europe/docs/testing/sandbox/).

```bash
npm install playwright && npx playwright install chromium
PAYU_SANDBOX=1 PAYU_SANDBOX_POS_ID=... PAYU_SANDBOX_SECOND_KEY=... \
PAYU_SANDBOX_CLIENT_ID=... PAYU_SANDBOX_CLIENT_SECRET=... \
vendor/bin/phpunit --testsuite=sandbox
```

PayU nie dostarczy powiadomień na komputer lokalny, więc te testy odczytują stan płatności z API PayU.
Dostarczanie powiadomień sprawdzisz, uruchamiając sklep pod publicznym adresem (np. przez tunel).

## Licencja

MIT, zobacz [LICENSE](LICENSE).
