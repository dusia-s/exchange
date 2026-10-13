# Fix plan

This plan lists the defects found in the Exchange API, ordered by severity, with a suggested fix for each.
Each fix names the files to change and the tests to add. Fixes 1–3 depend on each other and should go in together.

## Status

| Fix | State |
|-----|-------|
| 1–5 | Done |
| 6   | Done. Amounts are 4-decimal strings handled with bcmath (`App\Util\Money`). Wallet balance migrated to `DECIMAL(15,4)`. Amount validation (decimal format, max 4 places) moved here from fix 11, because bcmath rejects malformed input. |
| 7   | Done. `DatabaseTransactionManager`, `findByIdForUpdate()`, ordered wallet locks, and a transaction-row lock in the processor. Checked with 20 parallel transfers and two processor runs at the same time. |
| 15  | Done (found while doing fix 6, see below) |
| 8   | Done. Threshold is 15,000 EUR, measured on the amount sent, converted to EUR. |
| 9   | No change needed (see fix 9) |
| 10–13 | Done |
| 14  | Done, using a JSON error subscriber instead of changing `APP_ENV` (see fix 14) |
| Open question | The 0.25% in the original `testTransferSuccessfully` is still unresolved; the test currently expects `249.0000` |

## Target money flow (applies to fixes 1–3)

Transfers will **hold funds at creation time** and **settle at processing time**:

| Stage                          | Source wallet                | Target wallet        | Company wallet (target currency) |
|--------------------------------|------------------------------|----------------------|----------------------------------|
| `POST /api/wallets/transfer`   | debit `fromAmount` (hold)    | —                    | —                                |
| `app:process-transactions` → complete | —                     | credit `toAmount`    | credit `spread`                  |
| `app:process-transactions` → reject   | refund `fromAmount`   | —                    | —                                |

Debiting the source wallet straight away means a user can't create several pending transfers that together spend more than the balance.

---

## 1. Transfers move money twice (critical)

**Problem:** `TransferService::transfer()` updates both balances (`src/Service/TransferService.php:47-51`), and
`TransactionProcessorService::complete()` updates them again (`src/Service/TransactionProcessorService.php:32-38`).
A completed transfer debits the sender twice and credits the receiver twice. A rejected transfer keeps the money
moved by the first update.

**Fix:**
- `TransferService`: keep only the debit of the source wallet. Remove the credit to the target wallet.
- `TransactionProcessorService::complete()`: remove the source debit. Credit only the target wallet (and the company wallet, see fix 2).
- `TransactionProcessorService::reject()`: refund `fromAmount` to the source wallet, unless the transaction is not in `PENDING`/`FRAUD_REVIEW`.
- Make `complete()`/`reject()` act only on transactions in `PENDING` or `FRAUD_REVIEW`. Otherwise do nothing or throw, so a transaction can never be processed twice.
- If the target wallet is missing at completion, reject the transaction, which refunds the sender.

**Existing tests:** two tests fail today and disagree with each other about the intended flow. This plan follows the
hold-at-creation model:
- `TransactionProcessorServiceTest::testRejectSetsRejectedStatus` expects `reject()` to load and save the source wallet
  (a refund). This already matches the plan, so keep it and add an assertion on the refunded balance.
- `TransferServiceTest::testTransferSuccessfully` expects that creating a transfer changes no balance. Rewrite it: the source wallet
  is debited once, and the target wallet is untouched. Replace the `getBalance` consecutive-call stubs (they make the balance
  assertions meaningless) with real `Wallet` objects. For its `toAmount` expectation, see the open question at the end.
- `testCompleteUpdatesWalletBalancesAndSetsCompletedStatus` currently expects `complete()` to debit the source. Change it to expect
  only the target credit.

**Tests:** update `tests/Service/TransferServiceTest.php` and `tests/Service/TransactionProcessorServiceTest.php`:
- After a transfer, only the source wallet balance has changed.
- Completing a transfer credits only the target wallet.
- Rejecting a transfer refunds the source wallet.
- Processing an already completed or rejected transaction is a no-op or throws.

## 2. Spread earnings are never recorded (critical)

**Problem:** `CompanyWalletRepositoryInterface::addToBalance()` exists but nothing calls it, so `app:company-wallet` always
reports no earnings.

**Fix:** inject `CompanyWalletRepositoryInterface` into `TransactionProcessorService`. In `complete()`, call
`addToBalance($transaction->getToCurrency(), $transaction->getSpread())`. The spread is deducted in the target currency
(`TransferService.php:43-45`), so it belongs in that currency's company wallet. Don't credit on reject.
Skip the call when the spread is zero (same-currency transfer).

**Tests:** `complete()` calls `addToBalance` with the target currency and the spread. `reject()` never calls it.

## 3. No balance check: wallets can go negative (critical)

**Problem:** nothing checks that `fromAmount <= balance`.

**Fix:**
- Add `src/Exception/InsufficientFundsException.php`, following the pattern of the existing exceptions.
- In `TransferService`, throw it when the source balance is below `fromAmount`, before anything is debited.
- In `WalletController::transfer()`, map it to `422 Unprocessable Entity`.
- Hold funds at creation (see the target money flow above), so no second check is needed at completion.

**Tests:** a service test (the exception is thrown and nothing is saved) and a controller test (422).

## 4. Blocked wallets can transfer (high)

**Problem:** `DepositService` checks `isBlocked()`. `TransferService` checks neither the source wallet nor the target wallet.

**Fix:** in `TransferService`, throw the existing `WalletBlockedException` if either wallet is blocked, and map it to `422` in
`WalletController::transfer()` (as `deposit()` already does). In `TransactionProcessorService::complete()`, reject the
transaction (with a refund) if either wallet is blocked by the time it is processed.

**Tests:** a source wallet that is blocked, a target wallet that is blocked, and a wallet blocked between creation and processing.

## 5. A wallet can transfer to itself and create money (critical)

**Problem:** `fromWalletId === toWalletId` is allowed. `TransferService` loads the same row twice as two separate `Wallet`
objects, then saves the debited copy followed by the credited copy. The second save overwrites the first, so only
the credit survives. `TransactionProcessorService::complete()` does the same. In the running app, a 10 PLN self-transfer
**added** 10 PLN when it was created and another 10 PLN when it was completed.

**Fix:** in `WalletController::transfer()`, return `400` with `"Source and target wallet must differ."`. Also add a guard in
`TransferService`, since the service can be called from other places too. Once fix 1 is in, a transfer no longer
writes both wallets in the same step, but keep the guard anyway.

**Tests:** a controller test (400) and a service test (throws, nothing saved).

## 6. Money is handled as floats (high)

**Problem:** `wallets.balance` is `DOUBLE` and `Wallet`/`CompanyWallet` keep balances as `float`. All arithmetic uses
float casts (`(float) $amount`). That gives rounding drift (e.g. `0.1 + 0.2`). `transactions` and `company_wallets` already
use `DECIMAL(15,4)`, so storage is inconsistent too. The spread is also rounded to **2** decimals
(`SpreadService.php:30,41`) while amounts use 4. For JPY and HUF, a large share of the spread is lost to rounding.

**Fix:**
- Add `bcmath` to the `docker-php-ext-install` list in `Dockerfile` and require `ext-bcmath` in `composer.json`.
- New migration: `ALTER TABLE wallets MODIFY balance DECIMAL(15,4) NOT NULL DEFAULT 0`.
- Change balances in `Wallet` and `CompanyWallet` to `string`, and update both repositories' `buildEntity()`/save code.
- Rewrite the arithmetic in `DepositService`, `TransferService`, `TransactionProcessorService` and `SpreadService` with
  `bcadd`/`bcsub`/`bcmul`/`bcdiv` (scale 4; scale 6+ for intermediate rate math), or with PHP 8.4+'s `BcMath\Number`.
  Compare with `bccomp`.
- `SpreadService::calculateSpread()`: take a `string` price, return the spread at scale 4.
- Update `WalletResponse` and `ShowCompanyWalletCommand` formatting.
- Validate amount precision in the controller: reject more than 4 decimal places with `400`, instead of rounding without telling the user.

**Tests:** update the entity, DTO and service tests for string balances. Add a precision test (e.g. three deposits of `0.1` give exactly
`0.3000`), and a spread test for a JPY/HUF pair that checks the result keeps 4 decimal places.

## 7. Race conditions: no DB transactions or row locks (high)

**Problem:** every service reads a wallet, changes it in PHP and writes it back. Two requests running at the same time
(two transfers, or a deposit and a transfer) read the same balance, and the last write wins. The wallet update and the
transaction insert also aren't atomic. If one fails, the other has already happened.

**Fix:**
- Add `findByIdForUpdate(int $id): ?Wallet` to `WalletRepositoryInterface`/`WalletRepository`
  (`SELECT … FOR UPDATE`).
- Wrap `DepositService::deposit()`, `TransferService::transfer()` and `TransactionProcessorService::complete()/reject()` in
  `Connection::transactional()` (inject `Doctrine\DBAL\Connection`, or add a small `TransactionManager` wrapper so the
  unit tests can keep mocking).
- Lock wallets in ascending id order to avoid deadlocks when two transfers between A and B run in opposite directions.
- In `ProcessTransactionsCommand`, re-read and lock the transaction row before processing, so two command runs at the same time
  can't process it twice. Combine this with the status guard from fix 1.
- Optionally, make `wallets.update` atomic in SQL (`balance = balance + :delta`), as `CompanyWalletRepository::addToBalance()`
  already does.

**Tests:** check in unit tests that the transactional wrapper is called. A manual check: send many transfers at once
with `xargs -P` or `ab` and confirm the final balance.

## 8. Fraud threshold ignores currency (medium)

**Problem:** `requiresAntiFraudCheck: $toAmount > 15_000` (`TransferService.php:65`) compares a raw amount in whatever
the target currency is. 15,001 HUF (~177 PLN) goes to review, while 14,000 GBP (~68,000 PLN) doesn't.

**Fix (done):** the threshold is **15,000 EUR**, whatever the currencies (confirmed requirement). `TransferService` converts
the amount sent to EUR with `getExchangeRateBetween(fromCurrency, EUR)` and sends the transfer to review when that value is
strictly greater than `FRAUD_CHECK_THRESHOLD_EUR = '15000'`.

**Tests:** run against the real rates:
- 15,000 EUR is not reviewed; 15,000.0001 EUR is.
- 14,000 GBP (~16,121 EUR) is reviewed.
- 60,000 PLN (~14,155 EUR), which receives millions of HUF, is not reviewed.
- Other JPY, HUF and PLN cases on both sides of the threshold.

## 9. Anti-fraud timestamp set on automatic rejection (low)

**Problem:** `TransactionProcessorService::reject()` sets `antiFraudCheckedAt` whenever `requiresAntiFraudCheck` is true,
including when `complete()` rejects a transaction automatically because a wallet is missing. No review happened in that case.

**Outcome: no change needed.** On a second look, transactions that require an anti-fraud check are only ever processed
after the person running `app:process-transactions` answers its `confirm()` prompt. Every `complete()`/`reject()` call on such
a transaction therefore follows a human review, including an approval that is then rejected automatically because a wallet
is missing or blocked. The timestamp correctly records when the review happened.

## 10. Invalid JSON gives 500 (low)

**Problem:** `json_decode(..., JSON_THROW_ON_ERROR)` in every `WalletController` action throws `JsonException`, which isn't
caught.

**Fix:** add a private helper `decodeJson(Request): ?array` in `WalletController` that catches `JsonException`. Return
`400 {"error": "Invalid JSON body."}`. Also reject bodies that aren't arrays (e.g. `"abc"` or `123`).

## 11. Weak input validation (low)

**Problem:**
- `(int) $data['fromWalletId']` turns `"abc"` or `1.9` into `0` or `1` without an error.
- `is_numeric()` accepts `"1e3"` and `" 5"`. The raw string is stored on the transaction, so the API response contains
  `"fromAmount":"1e3"` (MariaDB saves it as `1000.0000`).
- `isset($data['currency'])` passes for non-strings, so `Currency::from([])` throws `TypeError`, not `ValueError`.

**Fix:**
- Require the wallet ids to be positive integers (`is_int` or `ctype_digit`).
- Check amounts with a strict decimal regex: `/^\d{1,11}(\.\d{1,4})?$/`.
- Check `is_string($data['currency'])` before `Currency::tryFrom()`.

Return `400` for each failure.

**Tests:** cases in `tests/Controller/WalletControllerTest.php`.

## 12. Non-numeric wallet id in the URL gives 500 (low)

**Problem:** `POST /api/wallets/abc/deposit` crashes with a `TypeError`, because the `{id}` route has no requirement and the action
takes an `int $id`.

**Fix:** add `requirements: ['id' => '\d+']` to the `#[Route('/{id}/deposit', ...)]` attribute in `WalletController`. Non-numeric ids then
return 404.

## 13. Missing token returns an HTML 401 (low)

**Problem:** a request without an `Authorization` header gets Symfony's HTML error page. Only an invalid token gets a JSON error,
from `TokenAuthenticator::onAuthenticationFailure()`.

**Fix:** have `TokenAuthenticator` implement `AuthenticationEntryPointInterface` and return a JSON `401` from `start()`,
or register a separate entry point in `config/packages/security.yaml`.

## 14. Debug mode is the default (low)

**Problem:** `.env` sets `APP_ENV=dev` and `APP_DEBUG=1`, so every error returns a full Symfony debug page with server file paths.

**Fix (done, different from the original suggestion):** `.env` keeps `APP_ENV=dev`. That's Symfony's convention for the
committed file, and the local Docker setup depends on it. Production must set `APP_ENV=prod`, `APP_DEBUG=0` and an
`APP_SECRET` as real environment variables. Instead, the new `App\EventSubscriber\ApiExceptionSubscriber` turns any uncaught
exception on `/api` routes into a JSON error with the HTTP status text (`500 Internal Server Error`, `404 Not Found`,
`405 Method Not Allowed`, ...). The exception message is added as `detail` only when `kernel.debug` is on, so production
responses never contain internals.

## 15. Migrations fail after the first run (high)

**Problem:** `.env` had `DATABASE_URL=...?serverVersion=11.4&charset=utf8`. Without a `-MariaDB` suffix, DBAL picks the MySQL
platform. MySQL's table-options query can't handle MariaDB's default `utf8mb4_uca1400_ai_ci` collation, which is the collation of
`doctrine_migration_versions`. Every `doctrine:migrations:migrate` after the first failed with `assert($options !== null)`.
`charset=utf8` also meant a 3-byte connection charset, while the tables are `utf8mb4`.

**Fix (done):** `serverVersion=11.4.0-MariaDB&charset=utf8mb4`.

---

## Open question: unexplained 0.25% in `testTransferSuccessfully`

The test mocks a rate of `0.25` and a spread of `1.00`, then transfers `1000.00`. With the formula in the README and the
code, the result is `1000 × 0.25 − 1.00 = 249.0000`, but the test expects `248.3775`. That equals `249 × 0.9975`: an extra
0.25% deducted after the spread. That fee doesn't appear in the README or anywhere else in the code. The assertion has
never run, because the test fails earlier on `setBalance`.

- **If it's a mistake in the test:** change the expectation to `249.0000`.
- **If it's a real requirement** (e.g. a flat 0.25% transaction fee): it needs a definition — which currency it's charged in, whether it
  goes to the company wallet, and whether it shows separately on the transaction. Then implement it alongside fix 2.

Decide this before rewriting the test in fix 1.

---

## Suggested order

1. Fix 6 (money as strings and bcmath). It touches every later change, so do it first to avoid rewriting the arithmetic twice.
2. Fixes 1, 2, 3 and 5 together (money flow, including the self-transfer guard).
3. Fix 7 (transactions and locking).
4. Fixes 4 and 8.
5. Fixes 9–14.

## Verification

- `docker compose up -d --build` (rebuild for bcmath), then run `docker exec -it php-fpm composer install`, `docker exec -it php-fpm php bin/console doctrine:migrations:migrate`, and `docker exec -it php-fpm composer tests`.
- Manual end-to-end test with the Postman collection:
  1. Create a user and PLN/HUF wallets, and deposit 1000 PLN.
  2. Transfer 100 PLN→HUF. PLN is now 900; HUF hasn't changed.
  3. Run `app:process-transactions`. HUF is credited, and `app:company-wallet` shows the HUF spread.
  4. Repeat, rejecting a fraud-review transfer. PLN is refunded.
  5. Try overspending, a blocked wallet, the same wallet as both ends, invalid JSON, `"1e3"`, `/api/wallets/abc/deposit`
     and a missing token. Each returns a 4xx JSON error.
