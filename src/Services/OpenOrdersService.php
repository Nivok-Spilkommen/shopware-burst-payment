<?php declare(strict_types=1);

namespace Burst\BurstPayment\Services;

use Burst\BurstPayment\BurstApi\BurstApiException;
use Burst\BurstPayment\BurstApi\BurstApiFactory;
use Burst\BurstPayment\Config\PluginConfigService;
use Burst\BurstPayment\Payment\BurstPaymentHandler;
use Burst\BurstPayment\Util\Util;
use DateTime;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class OpenOrdersService
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    /**
     * @var OrderTransactionService
     */
    private $orderTransactionService;

    /**
     * @var BurstApiFactory
     */
    private $burstApiFactory;

    /**
     * @var PluginConfigService
     */
    private $pluginConfigService;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    public function __construct(
        LoggerInterface $logger,
        EntityRepositoryInterface $orderTransactionRepository,
        OrderTransactionService $orderTransactionService,
        BurstApiFactory $burstApiFactory,
        PluginConfigService $pluginConfigService,
        OrderTransactionStateHandler $orderTransactionStateHandler
    ) {
        $this->logger = $logger;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->orderTransactionService = $orderTransactionService;
        $this->burstApiFactory = $burstApiFactory;
        $this->pluginConfigService = $pluginConfigService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
    }

    public function matchUnmatchedOrders(Context $context): void
    {
        $unmatchedOrderTransactions = $this->getUnmatchedOrderTransactions($context);
        if (count($unmatchedOrderTransactions) === 0) {
            return;
        }

        /** @var OrderTransactionEntity $oldestOrderTransaction */
        $oldestOrderTransaction = $unmatchedOrderTransactions[0];
        $oldestOrderOrderDateTime = $oldestOrderTransaction->getOrder()->getOrderDateTime();

        $burstApi = $this->burstApiFactory->createBurstApiForSalesChannel();
        $unconfirmedTransactions = $burstApi->getUnconfirmedTransactions();
        $transactions = $burstApi->getTransactionsFrom($oldestOrderOrderDateTime);

        $pluginConfig = $this->pluginConfigService->getPluginConfigForSalesChannel();
        $requiredConfirmationCount = $pluginConfig->getRequiredConfirmationCount() ?: 6;
        $cancelUnmatchedOrdersAfterMinutes = $pluginConfig->getCancelUnmatchedOrdersAfterMinutes();
        /** @var OrderTransactionEntity $orderTransaction */
        foreach ($unmatchedOrderTransactions as $orderTransaction) {
            $paymentContext = $this->orderTransactionService->getBurstPaymentContext($orderTransaction);
            $amountToPayInNQT = $paymentContext['amountToPayInNQT'] ?? null;
            if ($amountToPayInNQT === null) {
                $matchingTransaction = null;
            } else {
                $matchingTransaction = $this->getTransactionWithAmount(
                    $unconfirmedTransactions,
                    $transactions,
                    $amountToPayInNQT
                );
            }
            if (!$matchingTransaction) {
                $orderDateTime = $orderTransaction->getOrder()->getOrderDateTime();
                $diffInSeconds = (new DateTime('NOW'))->getTimestamp() - $orderDateTime->getTimestamp();
                if ($cancelUnmatchedOrdersAfterMinutes && $diffInSeconds > $cancelUnmatchedOrdersAfterMinutes * 60) {
                    $this->logger->info(
                        'Canceling order because unmatched for greater than ' . $cancelUnmatchedOrdersAfterMinutes . ' minutes',
                        [
                            'orderNumber' => $orderTransaction->getOrder()->getOrderNumber(),
                        ]
                    );
                    $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
                    $paymentContext['transactionState'] = 'cancelled';
                    $this->orderTransactionService->setBurstPaymentContext(
                        $orderTransaction,
                        $context,
                        $paymentContext
                    );
                }
                continue;
            }
            $this->logger->debug(
                'Matched order successfully',
                [
                    'orderNumber' => $orderTransaction->getOrder()->getOrderNumber(),
                    'burstTransactionId' => $matchingTransaction['transaction'],
                ]
            );
            $paymentContext['transactionId'] = $matchingTransaction['transaction'];
            $paymentContext['senderAddress'] = $matchingTransaction['senderRS'];
            $paymentContext['transactionState'] = $this->getTransactionState($matchingTransaction, $requiredConfirmationCount);
            $this->orderTransactionService->setBurstPaymentContext(
                $orderTransaction,
                $context,
                $paymentContext
            );
            if ($paymentContext['transactionState'] !== 'confirmed') {
                continue;
            }
            $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
            $this->logger->debug(
                'Marked order as paid after transaction matured',
                [
                    'orderNumber' => $orderTransaction->getOrder()->getOrderNumber(),
                    'burstTransactionId' => $matchingTransaction['transaction'],
                    'confirmations' => $paymentContext['confirmations'],
                ]
            );
        }
    }

    public function updateMatchedOrders(Context $context): void
    {
        $matchedOrderTransactions = $this->getMatchedOrderTransactions($context);
        if (count($matchedOrderTransactions) === 0) {
            return;
        }

        $burstApi = $this->burstApiFactory->createBurstApiForSalesChannel();
        $pluginConfig = $this->pluginConfigService->getPluginConfigForSalesChannel();
        $requiredConfirmationCount = $pluginConfig->getRequiredConfirmationCount() ?: 6;
        foreach ($matchedOrderTransactions as $orderTransaction) {
            $paymentContext = $this->orderTransactionService->getBurstPaymentContext($orderTransaction);
            try {
                $transaction = $burstApi->getTransaction($paymentContext['transactionId']);
            } catch (BurstApiException $e) {
                // Handle transaction not existing anymore
                if ($e->getCode() === 5 && $e->getMessage() === 'Unknown transaction') {
                    $paymentContext['transactionState'] = 'unmatched';
                    unset(
                        $paymentContext['confirmations'],
                        $paymentContext['senderAddress'],
                        $paymentContext['transactionId']
                    );
                    $this->orderTransactionService->setBurstPaymentContext(
                        $orderTransaction,
                        $context,
                        $paymentContext
                    );
                }
                continue;
            }
            $confirmationCount = $transaction['confirmations'] ?? 0;
            if ((isset($paymentContext['confirmations'] )) && $paymentContext['confirmations'] === $confirmationCount) {
                continue;
            }
            $paymentContext['confirmations'] = $confirmationCount;
            $paymentContext['transactionState'] = $this->getTransactionState($transaction, $requiredConfirmationCount);
            $this->orderTransactionService->setBurstPaymentContext(
                $orderTransaction,
                $context,
                $paymentContext
            );
            if ($paymentContext['transactionState'] !== 'confirmed') {
                continue;
            }
            $this->orderTransactionStateHandler->pay($orderTransaction->getId(), $context);
            $this->logger->debug(
                'Marked order as paid after transaction matured',
                [
                    'orderNumber' => $orderTransaction->getOrder()->getOrderNumber(),
                    'burstTransactionId' => $paymentContext['transactionId'],
                    'confirmations' => $paymentContext['confirmations'],
                ]
            );
        }
    }

    private function getTransactionState(array $transaction, int $requiredConfirmationCount): string
    {
        if (!isset($transaction['confirmations'])) {
            return 'unconfirmed';
        }

        return $transaction['confirmations'] >= $requiredConfirmationCount ? 'confirmed' : 'pending';
    }

    private function getTransactionWithAmount(array $unconfirmedTransactions, array $transactions, string $amountInNQT): ?array
    {
        $found = Util::array_find($unconfirmedTransactions, static function ($transaction) use ($amountInNQT) {
            return $transaction['amountNQT'] === $amountInNQT;
        });
        if ($found) {
            return $found;
        }
        $found = Util::array_find($transactions, static function ($transaction) use ($amountInNQT) {
            return $transaction['amountNQT'] === $amountInNQT;
        });

        return $found;
    }

    private function getUnmatchedOrderTransactions(Context $context): array
    {
        $openBurstOrderTransactions = $this->getOpenBurstOrderTransactions($context);

        return array_values(array_filter($openBurstOrderTransactions, function (OrderTransactionEntity $orderTransaction) {
            $paymentContext = $this->orderTransactionService->getBurstPaymentContext($orderTransaction);

            return !isset($paymentContext['transactionId']);
        }));
    }

    private function getMatchedOrderTransactions(Context $context): array
    {
        $openBurstOrderTransactions = $this->getOpenBurstOrderTransactions($context);

        return array_values(array_filter($openBurstOrderTransactions, function (OrderTransactionEntity $orderTransaction) {
            $paymentContext = $this->orderTransactionService->getBurstPaymentContext($orderTransaction);

            return isset($paymentContext['transactionId']);
        }));
    }

    private function getOpenBurstOrderTransactions(Context $context): array
    {
        return $this->orderTransactionRepository->search(
            (new Criteria())->addFilter(
                new EqualsFilter('order_transaction.stateMachineState.technicalName', 'open'),
                new EqualsFilter('order_transaction.paymentMethod.handlerIdentifier', BurstPaymentHandler::IDENTIFIER)
            )->addAssociations([
                'order',
            ])->addSorting(
                new FieldSorting('order_transaction.order.orderDateTime', FieldSorting::ASCENDING)
            ),
            $context
        )->getElements();
    }
}
