<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TransactionResponse;
use App\Dto\WalletResponse;
use App\Entity\User;
use App\Enum\Currency;
use App\Exception\InsufficientFundsException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use App\Util\Money;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/wallets')]
final class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly TransferService $transferService,
        private readonly DepositService $depositService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $wallets = $this->walletRepository->findByUserId($user->getIdNotNull());

        return new JsonResponse(array_map(static fn ($w) => new WalletResponse($w), $wallets));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if (null === $data) {
            return $this->invalidJsonResponse();
        }

        if (!isset($data['currency'])) {
            return new JsonResponse(['error' => 'Missing required field: currency.'], Response::HTTP_BAD_REQUEST);
        }

        $currency = is_string($data['currency']) ? Currency::tryFrom($data['currency']) : null;
        if (null === $currency) {
            return new JsonResponse(['error' => 'Invalid currency.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $wallet = $this->walletService->createWallet($user->getIdNotNull(), $currency);
        } catch (WalletAlreadyExistsException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(new WalletResponse($wallet), Response::HTTP_CREATED);
    }

    #[Route('/transfer', methods: ['POST'])]
    public function transfer(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if (null === $data) {
            return $this->invalidJsonResponse();
        }

        foreach (['fromWalletId', 'toWalletId', 'amount'] as $field) {
            if (!isset($data[$field])) {
                return new JsonResponse(['error' => sprintf('Missing required field: %s.', $field)], Response::HTTP_BAD_REQUEST);
            }
        }

        $walletIds = [];
        foreach (['fromWalletId', 'toWalletId'] as $field) {
            $walletIds[$field] = $this->parseId($data[$field]);
            if (null === $walletIds[$field]) {
                return new JsonResponse(['error' => sprintf('%s must be a positive integer.', $field)], Response::HTTP_BAD_REQUEST);
            }
        }

        $amountError = $this->validateAmount($data['amount']);
        if (null !== $amountError) {
            return new JsonResponse(['error' => $amountError], Response::HTTP_BAD_REQUEST);
        }

        if ($walletIds['fromWalletId'] === $walletIds['toWalletId']) {
            return new JsonResponse(['error' => 'Source and target wallet must differ.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $transaction = $this->transferService->transfer(
                $user->getIdNotNull(),
                $walletIds['fromWalletId'],
                $walletIds['toWalletId'],
                (string) $data['amount'],
            );
        } catch (SameWalletTransferException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WalletBlockedException|InsufficientFundsException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(new TransactionResponse($transaction), Response::HTTP_CREATED);
    }

    #[Route('/{id}/deposit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deposit(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if (null === $data) {
            return $this->invalidJsonResponse();
        }

        if (!isset($data['amount'])) {
            return new JsonResponse(['error' => 'Missing required field: amount.'], Response::HTTP_BAD_REQUEST);
        }

        $amountError = $this->validateAmount($data['amount']);
        if (null !== $amountError) {
            return new JsonResponse(['error' => $amountError], Response::HTTP_BAD_REQUEST);
        }

        if (bccomp((string) $data['amount'], DepositService::MAX_AMOUNT, Money::SCALE) > 0) {
            return new JsonResponse(['error' => sprintf('Amount cannot exceed %s.', DepositService::MAX_AMOUNT)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $wallet = $this->depositService->deposit(
                $user->getIdNotNull(),
                $id,
                (string) $data['amount'],
            );
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WalletBlockedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(new WalletResponse($wallet));
    }

    /**
     * Decodes the request body as a JSON object; an empty body counts as an empty object.
     *
     * @return array<mixed>|null null when the body is not valid JSON or not a JSON object
     */
    private function decodeJsonObject(Request $request): ?array
    {
        $content = $request->getContent();
        if ('' === trim($content)) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        // A JSON array such as [1, 2] decodes to a list; only objects (or {}) are accepted.
        if (!is_array($data) || ([] !== $data && array_is_list($data))) {
            return null;
        }

        return $data;
    }

    private function invalidJsonResponse(): JsonResponse
    {
        return new JsonResponse(['error' => 'Request body must be a valid JSON object.'], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Accepts a positive JSON integer or a string of digits, so "abc" or 1.9 are rejected instead of being cast silently.
     */
    private function parseId(mixed $value): ?int
    {
        if (is_string($value) && 1 === preg_match('/^\d{1,10}$/', $value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Returns an error message, or null when the amount is a positive decimal the bcmath-based services can handle.
     */
    private function validateAmount(mixed $amount): ?string
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            return 'Amount must be a positive number.';
        }

        $amount = (string) $amount;

        if (1 !== preg_match('/^\d{1,11}(\.\d+)?$/', $amount)) {
            return 'Amount must be a positive number.';
        }

        if (1 !== preg_match('/^\d+(\.\d{1,4})?$/', $amount)) {
            return sprintf('Amount must have at most %d decimal places.', Money::SCALE);
        }

        if (bccomp($amount, '0', Money::SCALE) <= 0) {
            return 'Amount must be a positive number.';
        }

        return null;
    }
}
