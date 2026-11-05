# Sylius Tpay Plugin

[![Build](https://github.com/refberg/SyliusTpayPlugin/actions/workflows/build.yaml/badge.svg?branch=main)](https://github.com/refberg/SyliusTpayPlugin/actions/workflows/build.yaml)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4%20%7C%208.5-777bb4)](composer.json)
[![Sylius](https://img.shields.io/badge/Sylius-2.2%20%7C%202.3-1abb9c)](https://sylius.com)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208-000000)](https://symfony.com)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

[English version](README.md)

Bramka płatności Tpay dla Sylius 2, oparta na Payment Requests Syliusa i [Tpay OpenAPI](https://openapi.tpay.com/).
Nie korzysta z Payum.

## Funkcje

- Strona płatności Tpay (przekierowanie) w checkoucie sklepu i w API Syliusa. Klient wybiera kanał płatności
  (przelew, BLIK, karta, ...) na stronie Tpay.
- Stan płatności zmienia tylko podpisana notyfikacja Tpay albo bezpośrednie zapytanie do API Tpay.
  Powrót klienta z Tpay nigdy nie jest dowodem zapłaty.
- Notyfikacje są weryfikowane dwukrotnie (`md5sum` z kodem bezpieczeństwa i nagłówek `X-JWS-Signature`
  z certyfikatem Tpay), potem transakcja jest ponownie odczytywana z API Tpay i porównywana z płatnością Syliusa:
  ID i tytuł transakcji, ID sprzedawcy, `hiddenDescription`, kwota i waluta.
- Zduplikowane i spóźnione notyfikacje nie zmieniają wyniku i nigdy nie opłacają zamówienia dwa razy.
- Zwroty z panelu admina; zwroty zrobione w panelu Tpay są synchronizowane.
- Anulowanie płatności w Syliusie anuluje oczekującą transakcję Tpay.
- Dane dostępowe są przechowywane w postaci zaszyfrowanej przez Syliusa i nie trafiają do logów.

Poza zakresem: z góry wybrany kanał płatności (BLIK Level 0, formularz karty na stronie sklepu), zapisane karty,
płatności cykliczne, marketplace, zwroty częściowe zlecane z Syliusa.

## Wymagania

| Sylius  | PHP     | Symfony                                   |
|---------|---------|-------------------------------------------|
| 2.2.12+ | 8.2–8.4 | 6.4, 7.4                                  |
| 2.3.2+  | 8.3–8.5 | 6.4, 7.4, 8.x (8.x wymaga PHP 8.4+)       |

Rozszerzenia PHP: `openssl` (podpisy notyfikacji), `json`, `mbstring`.

Sklep Syliusa (`SyliusShopBundle`) i panel admina (`SyliusAdminBundle`) są opcjonalne, więc plugin działa też
w aplikacjach wyłącznie z API.

Maszyna stanów `sylius_payment` musi używać adaptera Symfony Workflow, który jest domyślny w Syliusie.
Plugin wysyła zwroty i anulowania do Tpay z listenerów zdarzeń `workflow.sylius_payment.completed.refund`
i `workflow.sylius_payment.completed.cancel`. Gdy `sylius_payment` używa adaptera winzou
(`winzou_state_machine` w `sylius_state_machine_abstraction.graphs_to_adapters_mapping` albo `default_adapter`),
zwroty i anulowania zrobione w Syliusie nie trafiają do Tpay i trzeba je wykonać w panelu Tpay.

Konto Tpay musi obsługiwać walutę zamówienia. Bez umowy na wiele walut Tpay przyjmuje tylko PLN;
transakcja w innej walucie się nie utworzy i klient nie zapłaci.

## Instalacja

1. Zainstaluj pakiet:

   ```bash
   composer require refberg/sylius-tpay-plugin
   ```

2. Zarejestruj bundle w `config/bundles.php`, jeśli nie zrobił tego Symfony Flex:

   ```php
   Refberg\SyliusTpayPlugin\RefbergSyliusTpayPlugin::class => ['all' => true],
   ```

3. Zaimportuj konfigurację w `config/packages/refberg_sylius_tpay.yaml`:

   ```yaml
   imports:
       - { resource: "@RefbergSyliusTpayPlugin/config/config.yaml" }
   ```

4. Upewnij się, że istnieje klucz szyfrowania konfiguracji bramek Syliusa (Sylius tworzy go przy instalacji):

   ```bash
   bin/console sylius:payment:generate-key
   ```

5. Tylko aplikacje bez sklepu Syliusa: zaimportuj trasę notyfikacji płatności, którą Sylius w innym przypadku importuje
   razem z trasami sklepu, w `config/routes/sylius_payment.yaml`:

   ```yaml
   sylius_payment_notify:
       resource: "@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml"
   ```

   Bez tej trasy żądania capture kończą się błędem, a Tpay nie może dostarczyć notyfikacji. W aplikacji z panelem admina
   nie wyświetli się też strona edycji metody płatności Tpay, bo pokazuje adres notyfikacji.
   Klienci API muszą też wysyłać `continue_url` w payloadzie capture (zobacz [API](#api)).

Migracje bazy nie są potrzebne. Plugin trzyma swoje dane w `sylius_payment.details`.

## Konfiguracja

W panelu sprzedawcy Tpay:

1. **Integracja → API**: utwórz klucz OpenAPI; dostaniesz client ID i client secret.
2. **Ustawienia → Powiadomienia**: skopiuj **kod bezpieczeństwa** i włącz **Zezwalaj na nadpisanie** adresu powiadomień.
   Plugin wysyła swój adres notyfikacji przy każdej transakcji; bez tej opcji Tpay wysyła notyfikacje
   na adres ustawiony w panelu.

W panelu admina Syliusa przejdź do **Konfiguracja → Metody płatności → Utwórz → Tpay** i wypełnij:

| Pole                             | Panel Tpay                                         |
|----------------------------------|----------------------------------------------------|
| Środowisko                       | sandbox (`openapi.sandbox.tpay.com`) albo produkcja (`api.tpay.com`) |
| ID sprzedawcy                    | ID sprzedawcy (cyfry)                              |
| Klucz API (client ID)            | klucz API – client ID                              |
| Hasło klucza API (client secret) | klucz API – secret                                 |
| Kod bezpieczeństwa powiadomień   | Powiadomienia → Bezpieczeństwo → kod bezpieczeństwa |

Client secret i kod bezpieczeństwa nie są pokazywane po zapisaniu.
Zostaw te pola puste, aby zachować zapisane wartości.

Adres notyfikacji to `https://<twój-sklep>/payment-methods/<KOD_METODY_PŁATNOŚCI>`. Pokazuje go strona edycji
metody płatności. Tpay nie podąża za przekierowaniami przy notyfikacjach, więc musi to być końcowy publiczny adres HTTPS.

Tpay ponawia notyfikację, dopóki nie dostanie HTTP 200 z treścią `TRUE`: do 37 razy przez około dwa dni
(najpierw co minutę, potem co 3 i 10 minut, potem co godzinę). HTTP 404 zatrzymuje ponowienia. Tpay nie publikuje
stałej listy adresów IP notyfikacji; notyfikacje są uwierzytelniane podpisami.

Jeśli payment requesty są przetwarzane asynchronicznie (`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN` inny niż `sync://`),
ustaw `framework.router.default_uri` na publiczny adres sklepu, aby adres notyfikacji był poprawny.
Opisane niżej odpowiedzi na notyfikacje też zakładają `sync://` (zobacz [Notyfikacje](#notyfikacje)).

Żądania do Tpay (OAuth, OpenAPI i pobieranie certyfikatów) mają limit bezczynności 15 sekund, ustawiany parametrem
`refberg_tpay.http_timeout`. Aby go zmienić, ustaw parametr w konfiguracji aplikacji:

```yaml
parameters:
    refberg_tpay.http_timeout: 30.0
```

### Tworzenie metody płatności poza panelem admina

Metoda płatności Tpay tworzona w fixturach, migracjach albo w kodzie musi mieć `usePayum` ustawione na `false`.
Sylius domyślnie ustawia `true`; formularz admina sam to wyłącza dla bramek bez fabryki Payum.
Przy `usePayum` równym `true` sklep przekazuje płatność do Payum zamiast do pluginu, więc strona płatności
kończy się błędem (Payum nie ma takiej bramki), a Sylius nie szyfruje konfiguracji bramki.

Fixtura (`sylius_fixtures`):

```yaml
sylius_fixtures:
    suites:
        default:
            fixtures:
                payment_method:
                    options:
                        custom:
                            tpay:
                                code: TPAY
                                name: Tpay
                                gatewayName: tpay
                                gatewayFactory: !php/const Refberg\SyliusTpayPlugin\TpayGateway::FACTORY_NAME
                                gatewayConfig:
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_ENVIRONMENT: sandbox
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_MERCHANT_ID: '...'
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_CLIENT_ID: '...'
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_CLIENT_SECRET: '...'
                                    !php/const Refberg\SyliusTpayPlugin\TpayGateway::CONFIG_SECURITY_CODE: '...'
                                usePayum: false
                                enabled: true
                                channels: [FASHION_WEB]
```

Kod, z serwisami `sylius.factory.payment_method` i `sylius.repository.payment_method`:

```php
use Refberg\SyliusTpayPlugin\TpayGateway;

$paymentMethod = $paymentMethodFactory->createWithGateway(TpayGateway::FACTORY_NAME);
$paymentMethod->setCode('TPAY');
$paymentMethod->setName('Tpay');
$paymentMethod->addChannel($channel);

/** @var \Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface $gatewayConfig */
$gatewayConfig = $paymentMethod->getGatewayConfig();
$gatewayConfig->setGatewayName('tpay');
$gatewayConfig->setUsePayum(false);
$gatewayConfig->setConfig([
    TpayGateway::CONFIG_ENVIRONMENT => 'sandbox',
    TpayGateway::CONFIG_MERCHANT_ID => '...',
    TpayGateway::CONFIG_CLIENT_ID => '...',
    TpayGateway::CONFIG_CLIENT_SECRET => '...',
    TpayGateway::CONFIG_SECURITY_CODE => '...',
]);

$paymentMethodRepository->add($paymentMethod);
```

## Jak to działa

### Sklep

1. Klient składa zamówienie i klika **Zapłać**. Powstaje transakcja Tpay
   (`hiddenDescription` = `<numer zamówienia>-<ID płatności>-<12 znaków hasha payment requestu>`),
   a klient trafia na stronę płatności Tpay.
2. Po płatności klient wraca do sklepu (`/order/after-pay/{hash}?tpay_return=success|error`).
   Plugin pyta API Tpay o status transakcji. Gdy transakcja wciąż oczekuje (np. przelew jeszcze niezaksięgowany),
   klient wracający adresem sukcesu widzi stronę podziękowania, a płatność pozostaje nieopłacona;
   wracającemu adresem błędu Sylius proponuje ponowną płatność.
3. Tpay wysyła notyfikację na `/payment-methods/{code}`. Płatność jest kończona albo oznaczana jako nieudana
   na podstawie transakcji odczytanej z API Tpay.

Ponowne otwarcie strony płatności używa transakcji Tpay, która jest w toku, zamiast tworzyć nową.

### API

Utwórz payment request dla złożonego zamówienia:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "TPAY",
    "payload": {"continue_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` zawiera stronę płatności Tpay, `responseData.transaction_id` i `responseData.title`
identyfikują transakcję. `continue_url` służy jako adres powrotu po sukcesie i po błędzie.
Jest opcjonalne, gdy sklep jest zainstalowany; bez niego klient wraca na stronę after-pay sklepu.
Bez sklepu żądanie capture bez `continue_url` kończy się błędem i Tpay nie jest wywoływany.

Gdy klient wróci, zapytaj o status:

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{"paymentId": 123, "paymentMethodCode": "TPAY", "action": "status"}
```

`responseData.payment_state` zawiera stan płatności Syliusa, a `responseData.tpay_status` status transakcji Tpay.

### Reguły stanu płatności

| Status transakcji Tpay   | Płatność Syliusa                                                                |
|--------------------------|---------------------------------------------------------------------------------|
| `pending`                | bez zmian (`new`)                                                               |
| `paid`, `correct`        | `completed`, jeden raz                                                          |
| `refund` (pełny zwrot)   | `refunded`, gdy to ta transakcja opłaciła płatność                              |
| `canceled`               | `failed`, tylko gdy żadna inna transakcja Tpay tej płatności nie oczekuje       |

Zakończona płatność nigdy nie wraca do wcześniejszego stanu. Gdy druga transakcja Tpay tej samej płatności zostanie
opłacona (np. po ponownej próbie klienta w innej karcie), zamówienie Syliusa nie jest opłacane drugi raz. Plugin
zapisuje ostrzeżenie w szczegółach płatności (`refberg_tpay.warnings`) i loguje błąd na kanale `tpay`.
Zwróć tę transakcję ręcznie w panelu Tpay.

Gdy klient po przekierowaniu do Tpay zmieni metodę płatności zamówienia, transakcja Tpay utworzona przed zmianą
pozostaje powiązana z płatnością i nadal można ją opłacić. Płatność kończy jej notyfikacja albo sprawdzenie statusu
(powrót klienta z Tpay albo payment request `status` przez API), gdy API Tpay potwierdzi zapłatę; plugin przywraca
metodę płatności Tpay, która została opłacona, zapisuje ostrzeżenie `paid_with_previous_payment_method` i loguje
ostrzeżenie. Dzięki temu zwroty tej płatności z Syliusa trafiają do Tpay.

Płatność anulowana w Syliusie, zanim stara transakcja Tpay zostanie opłacona, ma nadal nową metodę, więc anulowanie może
nie dotrzeć do tej transakcji:

- Inna metoda Tpay: anulowanie jest wysyłane z danymi nowej metody. Dla transakcji innego sprzedawcy Tpay powinien
  odmówić; payment request kończy się wtedy błędem, a plugin loguje błąd.
- Metoda spoza Tpay: anulowanie nie trafia do Tpay. Stara transakcja dalej oczekuje; jeśli klient ją opłaci,
  płatność pozostaje anulowana i dostaje ostrzeżenie `payment_completed_after_failure`.

W obu przypadkach zwróć albo anuluj transakcję w panelu Tpay.

### Notyfikacje

| Sytuacja                                                                   | Odpowiedź        | Tpay              |
|----------------------------------------------------------------------------|------------------|-------------------|
| Przyjęta (także duplikat, nieaktualny status albo transakcja niepasująca do płatności) | 200 `TRUE` | kończy |
| Brak lub błędny `md5sum` albo podpis JWS, inne ID sprzedawcy                | 400, nie zapisana | ponawia          |
| Nieznana transakcja (`tr_crc` / `tr_id` niepowiązane z płatnością)          | 404, nie zapisana | kończy           |
| Metoda płatności bez danych dostępowych, niedostępny certyfikat Tpay        | 503, nie zapisana | ponawia          |
| API Tpay nieosiągalne, HTTP 5xx/408/429 albo odrzuca dane pluginu (401/403) | 5xx              | ponawia           |
| Inny błąd API Tpay (np. 400, 404) przy odczycie transakcji                  | 200 `TRUE`, payment request z błędem | kończy |

Odpowiedzi 5xx przy błędach przejściowych działają tylko przy transporcie payment requestów `sync://` (domyślnym
w Syliusie): wyjątek handlera dociera wtedy do endpointu notyfikacji. Przy transporcie asynchronicznym endpoint zawsze
odpowiada 200 `TRUE`, a błędy obsługuje strategia ponowień Messengera.

### Zwroty

Kliknij **Zwrot** przy zakończonej płatności Tpay w panelu admina. Plugin najpierw odczytuje z Tpay zwroty transakcji,
bo zwroty częściowe zrobione w panelu Tpay nie wysyłają notyfikacji. Potem:

- brak wcześniejszych zwrotów: zwracana jest cała transakcja (puste body żądania). Tpay ustawia status transakcji
  `refund` i potwierdza to notyfikacją `CHARGEBACK`, a plugin zapisuje statusy zwrotów;
- wcześniejsze zwroty, które nie są anulowane (także te w toku): zwracana jest tylko pozostała kwota;
- wcześniejsze zwroty pokrywające całą płatność: Tpay nie jest wywoływany, a payment request zwrotu się kończy.

Odejmowane są także zwroty z panelu, które są jeszcze w toku. Jeśli Tpay anuluje taki zwrot po zwrocie z Syliusa,
klient dostaje tylko kwotę wysłaną do Tpay (nic, gdy zwroty z panelu pokrywały całą płatność).
Płatność pozostaje `refunded`; gdy plugin odczyta anulowanie z Tpay (przy następnej notyfikacji `CHARGEBACK`),
zapisuje ostrzeżenie `refund_canceled` i loguje błąd. Resztę zwróć ręcznie w panelu Tpay.
To samo dotyczy anulowania przez Tpay zwrotu zleconego z Syliusa.

Sylius od razu oznacza płatność jako zwróconą, zanim Tpay przetworzy zwrot. Jeśli Tpay odrzuci zwrot,
payment request zwrotu kończy się błędem, a plugin loguje błąd. Zwrot trzeba wtedy obsłużyć w panelu Tpay.
Sylius nie ponawia nieudanego żądania automatycznie, także gdy Tpay był niedostępny (timeout, HTTP 5xx).

Tpay nie ma klucza idempotencji dla zwrotów. Żądanie zwrotu jest wysyłane tylko, gdy payment request zwrotu jest `new`,
pod blokadą płatności, a plugin pomija wywołanie, gdy zwrot z Syliusa jest już zapisany. Awaria między odpowiedzią Tpay
a zapisem do bazy może jednak zostawić zwrot, o którym Sylius nie wie; kolejna próba zwrotu odczyta go z Tpay jako zwrot
zrobiony poza Syliusem.

### Anulowanie

Gdy płatność Tpay zostanie anulowana w Syliusie (np. przy anulowaniu zamówienia), plugin anuluje każdą oczekującą
transakcję Tpay tej płatności (`POST /transactions/{transactionId}/cancel`). Jeśli Tpay odmówi, bo transakcja została
już opłacona (HTTP 409), payment request anulowania kończy się błędem, a plugin loguje błąd; późniejsza notyfikacja
jest zapisywana jako ostrzeżenie i płatność nie jest kończona. Sylius nie ponawia nieudanego anulowania automatycznie,
także gdy Tpay był niedostępny (timeout, HTTP 5xx); sprawdź transakcję w panelu Tpay.

### Zwroty zrobione w panelu Tpay

Pełny zwrot zrobiony w panelu Tpay ustawia status transakcji `refund`, a Tpay wysyła notyfikację `CHARGEBACK`.
Plugin zapisuje zwroty odczytane z API Tpay jako zewnętrzne i przenosi płatność do `refunded`;
payment request zwrotu utworzony przez to przejście widzi je i nie zwraca drugi raz.

Zwroty częściowe zrobione w panelu Tpay nie zmieniają statusu transakcji i nie wysyłają notyfikacji, nawet gdy
sumują się do całej kwoty. Pojawiają się w szczegółach płatności przy następnym zwrocie z Syliusa albo notyfikacji
`CHARGEBACK`. Płatność pozostaje `completed`, bo Sylius nie ma stanu dla częściowego zwrotu.

## Bezpieczeństwo

- Sekrety są przechowywane w konfiguracji bramki, którą Sylius szyfruje.
- Podpisy notyfikacji są sprawdzane przez `hash_equals()` (`md5sum`) i `openssl_verify()` (JWS, RS256). Certyfikat JWS
  musi pochodzić z `https://secure.tpay.com/x509/` (sandbox: `https://secure.sandbox.tpay.com/x509/`), być podpisany
  przez główny certyfikat Tpay z tego samego hosta i być ważny w chwili sprawdzenia. Certyfikaty są trzymane przez dobę
  w puli `refberg_tpay.cache` i pobierane ponownie po nieudanym sprawdzeniu.
- Zwroty i anulowania trafiają do Tpay tylko dla płatności już zwróconej albo anulowanej w Syliusie,
  więc nie da się ich wywołać przez shop API.
- Sekrety i tokeny OAuth nigdy nie trafiają do logów ani do danych payment requestów.
  Komunikaty wyjątków zawierają tylko kody HTTP i kody błędów Tpay.
- Token OAuth jest trzymany w puli `refberg_tpay.cache` (domyślnie `cache.app`) do chwili tuż przed wygaśnięciem.
- Ruch do Tpay przechodzi przez serwis `refberg_tpay.http_client` (alias `http_client`).
  Możesz go nadpisać, np. aby użyć proxy.

## Założenia sprawdzone tylko z dokumentacją

Te punkty pochodzą z dokumentacji Tpay i oficjalnego SDK PHP, a nie z testu na sandboxie Tpay.
Pokrywają je testy z `tests/Support/FakeTpay.php`, który implementuje je zgodnie z opisem; sprawdź je
przez `tests/Sandbox` przed uruchomieniem produkcyjnym.

| Założenie | Gdzie |
|-----------|-------|
| `POST /oauth/auth` przyjmuje body JSON (tak wysyła oficjalne SDK; dokumentacja API podaje multipart form data). | `AccessTokenProvider` |
| `GET /transactions/{transactionId}` przyjmuje ID transakcji zwrócone przy tworzeniu; notyfikacje wskazują transakcję po tytule (`tr_id`). | `TpayClient`, `NotifyPaymentRequestHandler` |
| Status zwrotu inny niż `done`, `authorized`, `blik_processed` (zakończony) albo `cancel`, `declined` (anulowany) oznacza zwrot w toku. Zwroty bez statusu (brak konta bankowego, karty) są w toku. | `TpayRefund`, `TpayClient` |
| `tr_status` to `TRUE` (albo `PAID`) dla płatności i `CHARGEBACK` dla pełnego zwrotu; zwroty częściowe nie wysyłają notyfikacji. | `NotificationParser` |
| Po zmianie metody płatności w Syliusie stara transakcja Tpay nadal przyjmuje wpłatę. | `ShopPaymentFlowTest` |
| Transakcja w walucie innej niż PLN jest odrzucana bez umowy na wiele walut. | `FakeTpay` |

## Rozszerzanie

Poniższe serwisy są zarejestrowane pod swoimi interfejsami. Dekoruj albo podmieniaj je po interfejsie:

| ID serwisu                                                              | Odpowiedzialność                                     |
|-------------------------------------------------------------------------|------------------------------------------------------|
| `Refberg\SyliusTpayPlugin\Api\AccessTokenProviderInterface`             | Token OAuth do API Tpay                              |
| `Refberg\SyliusTpayPlugin\Api\TpayClientInterface`                      | Wywołania Tpay OpenAPI                               |
| `Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface`          | Dane dostępowe Tpay z konfiguracji bramki            |
| `Refberg\SyliusTpayPlugin\Signature\ChecksumVerifierInterface`          | Weryfikacja `md5sum`                                 |
| `Refberg\SyliusTpayPlugin\Signature\JwsVerifierInterface`               | Weryfikacja `X-JWS-Signature`                        |
| `Refberg\SyliusTpayPlugin\Signature\CertificateProviderInterface`       | Pobieranie i cache certyfikatów Tpay                 |
| `Refberg\SyliusTpayPlugin\Notification\NotificationParserInterface`     | Parsowanie podpisanych notyfikacji                   |
| `Refberg\SyliusTpayPlugin\Order\TransactionRequestFactoryInterface`     | Body żądania utworzenia transakcji                   |
| `Refberg\SyliusTpayPlugin\Order\ExtOrderIdGeneratorInterface`           | Wartości `hiddenDescription`                         |
| `Refberg\SyliusTpayPlugin\Order\TpayTransactionValidatorInterface`      | Porównanie transakcji Tpay z płatnością Syliusa      |
| `Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface`               | Blokada płatności                                    |
| `Refberg\SyliusTpayPlugin\Payment\PaymentStateApplierInterface`         | Zmiany stanu płatności                               |
| `Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface`         | Odczyt, sprawdzenie i zastosowanie transakcji Tpay i jej zwrotów |

Na przykład, aby zmienić format `hiddenDescription`:

```yaml
services:
    App\Payment\ExtOrderIdGenerator:
        decorates: Refberg\SyliusTpayPlugin\Order\ExtOrderIdGeneratorInterface
        arguments: ['@.inner']
```

`extractPaymentId()` musi odczytać ID płatności z każdej wartości zwróconej przez `generate()`,
bo po nim notyfikacje są dopasowywane do płatności.

Klasy oznaczone `@internal` (handlery komend, `PaymentRequestHandlerTrait`, `Api\Json`) nie są objęte
obietnicą zgodności wstecznej.

## Rozwój

```bash
composer install
vendor/bin/ecs check
vendor/bin/rector process --dry-run
vendor/bin/composer-dependency-analyser
APP_ENV=test vendor/bin/console cache:warmup   # PHPStan czyta skompilowany kontener testowy
vendor/bin/phpstan analyse
vendor/bin/phpunit --testsuite=unit
```

PHPStan działa na poziomie max na całym repozytorium z regułami strict, deprecation, Doctrine, Symfony i PHPUnit.
CI sprawdza też `composer normalize`, `composer audit` i uruchamia testy mutacyjne Infection (`infection.json5`).

Testy integracyjne uruchamiają aplikację testową Syliusa na MySQL:

```bash
docker run -d --name tpay-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:8.0
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:schema:create
vendor/bin/phpunit --testsuite=integration
```

Aplikacja testowa szyfruje konfigurację bramek kluczem `tests/TestApplication/config/encryption/test-only.key`
z repozytorium, przetwarza payment requesty przez `sync://` i nie potrzebuje sieci (ikony Iconify są wyłączone).
Testy nigdy nie wywołują Tpay. Wszystkie żądania trafiają do atrapy sandboxa Tpay w pamięci (`tests/Support/FakeTpay.php`),
która podpisuje notyfikacje własnym testowym CA i odrzuca każdy inny host. Testy obejmują podpisane, zduplikowane,
spóźnione i podrobione notyfikacje. `HeadlessApiPaymentFlowTest` działa bez bundli sklepu, admina i Twig Hooks.

### Testy na sandboxie Tpay

`tests/Sandbox` działa na prawdziwym sandboxie Tpay (konta: [register.sandbox.tpay.com](https://register.sandbox.tpay.com/)).
Nie jest częścią CI i jest pomijany, dopóki go nie włączysz. Dane dostępowe są czytane tylko ze zmiennych środowiskowych.

```bash
TPAY_SANDBOX=1 TPAY_SANDBOX_MERCHANT_ID=... TPAY_SANDBOX_CLIENT_ID=... \
TPAY_SANDBOX_CLIENT_SECRET=... TPAY_SANDBOX_SECURITY_CODE=... \
vendor/bin/phpunit --testsuite=sandbox
```

Bez dodatkowych ustawień tworzy, odczytuje i anuluje transakcje. Z `TPAY_SANDBOX_MANUAL=1` dodatkowo wypisuje strony
płatności sandboxa i czeka do pięciu minut, aż je opłacisz albo odrzucisz, a potem sprawdza stan płatności i zwrot.
Tpay nie może dostarczyć notyfikacji na lokalną maszynę, więc te testy czytają stan płatności z API Tpay.
Aby sprawdzić dostarczanie notyfikacji, uruchom sklep pod publicznym adresem (np. przez tunel).

Scenariusze ręczne przed wydaniem: płatność, odrzucona płatność, zwrot z panelu admina, pełny i częściowy zwrot
w panelu Tpay, anulowanie, zmiana metody płatności po przekierowaniu do Tpay.

## Licencja

MIT, zobacz [LICENSE](LICENSE).
