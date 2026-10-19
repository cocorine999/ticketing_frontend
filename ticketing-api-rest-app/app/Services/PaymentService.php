<?php

namespace App\Services;

use App\Repositories\Contracts\TicketRepositoryContract;
use App\Repositories\Contracts\TicketTypeRepositoryContract;
use App\Services\Contracts\NotificationServiceContract;
use App\Services\Contracts\PaymentServiceContract;
use FedaPay\Customer;
use FedaPay\FedaPay;
use FedaPay\Transaction;
use FedaPay\Webhook;
use Illuminate\Support\Facades\Log;

class PaymentService implements PaymentServiceContract
{
    protected TicketTypeRepositoryContract $ticketTypeRepository;
    protected TicketRepositoryContract $ticketRepository;
    protected NotificationServiceContract $notificationService;

    public function __construct(
        TicketTypeRepositoryContract $ticketTypeRepository,
        TicketRepositoryContract $ticketRepository,
        NotificationServiceContract $notificationService
    ) {
        $this->ticketTypeRepository = $ticketTypeRepository;
        $this->ticketRepository = $ticketRepository;
        $this->notificationService = $notificationService;

        // Initialiser FedaPay
        FedaPay::setApiKey(config('services.fedapay.secret_key'));
        FedaPay::setEnvironment(config('services.fedapay.environment', 'sandbox'));
    }

    /**
     * Create a payment transaction for a specific ticket purchase
     *
     * @param array $ticketIds Array of ticket IDs for this purchase
     * @param array $customerData Customer information (firstname, lastname, email, phone_number)
     * @param int $amount Total amount
     * @param string $description Transaction description
     * @return array Payment information including payment_url
     */
    public function createTransactionForTicket(array $ticketIds, array $customerData, int $amount, string $description): array
    {
        try {
            // Récupérer les tickets pour obtenir les infos de l'événement et types
            $tickets = \App\Models\Ticket::whereIn('id', $ticketIds)->with(['ticketType.event'])->get();
            $firstTicket = $tickets->first();
            $event = $firstTicket->ticketType->event;

            // Calculer le résumé des types de tickets
            $ticketTypesSummary = $tickets->groupBy('ticket_type_id')
                ->map(function ($group) {
                    $ticketType = $group->first()->ticketType;
                    return [
                        'type' => $ticketType->name,
                        'count' => $group->count(),
                        'price' => $ticketType->price,
                    ];
                })
                ->values()
                ->toArray();

            // 1. CRÉER L'ENREGISTREMENT PAYMENT
            $payment = \App\Models\Payment::create([
                'amount' => $amount,
                'currency' => config('services.fedapay.currency', 'XOF'),
                'status' => 'pending',

                // Event details
                'event_id' => $event->id,
                'event_title' => $event->title,
                'event_start_date' => $event->start_datetime,
                'event_end_date' => $event->end_datetime,
                'event_location' => $event->location,

                // Customer details
                'customer_firstname' => $customerData['firstname'],
                'customer_lastname' => $customerData['lastname'],
                'customer_email' => $customerData['email'],
                'customer_phone' => $customerData['phone_number'] ?? null,

                // Purchase details
                'ticket_count' => count($ticketIds),
                'ticket_types_summary' => $ticketTypesSummary,

                // Metadata
                'metadata' => [
                    'ticket_ids' => $ticketIds,
                    'description' => $description,
                ],
            ]);

            // 2. LIER LES TICKETS AU PAYMENT
            foreach ($ticketIds as $ticketId) {
                $ticket = $this->ticketRepository->find($ticketId);
                $this->ticketRepository->update($ticket, [
                    'payment_id' => $payment->id
                ]);
            }

            // 3. CRÉER LA TRANSACTION FEDAPAY
            $transaction = Transaction::create([
                'description' => $description,
                'amount' => $amount,
                "mode" => "bank_transfer",
                "provider" => "visa",
                'currency' => ['iso' => config('services.fedapay.currency', 'XOF')],
                'callback_url' => route('payment.callback'),
                'customer' => [
                    'firstname' => $customerData['firstname'],
                    'lastname' => $customerData['lastname'],
                    'email' => $customerData['email']
                ],
                'merchant_reference' => 'payment-' . $payment->id,  // ✅ Référence au payment
                'custom_metadata' => [
                    'payment_id' => $payment->id,  // ✅ ID du payment
                    'event_title' => $event->title,
                    'ticket_count' => count($ticketIds),
                ],
            ]);

            // 4. METTRE À JOUR LE PAYMENT AVEC L'ID FEDAPAY
            $payment->update([
                'fedapay_transaction_id' => $transaction->id,
            ]);

            // 5. GÉNÉRER LE TOKEN
            $token = $transaction->generateToken();

            return [
                'payment_id' => $payment->id,  // ✅ Retourner l'ID du payment
                'transaction_id' => $transaction->id,
                'token' => $token->token,
                'payment_url' => $token->url,
                'amount' => $amount,
                'currency' => config('services.fedapay.currency', 'XOF'),
            ];
        } catch (\Exception $e) {
            Log::error('Payment creation failed', [
                'ticket_ids' => $ticketIds,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to create payment: ' . $e->getMessage());
        }
    }

    /**
     * @deprecated This method is deprecated. Use createTransactionForTicket instead.
     */
    public function createPaymentLinkForTicketType(string $ticketTypeId): array
    {
        $ticketType = $this->ticketTypeRepository->findOrFail($ticketTypeId);

        try {
            // Créer une transaction FedaPay
            $transaction = Transaction::create([
                'description' => "Ticket: {$ticketType->name}",
                'amount' => $ticketType->price,
                'currency' => ['iso' => config('services.fedapay.currency', 'XOF')],
                'callback_url' => route('payment.callback'),
                'custom_metadata' => [
                    'ticket_type_id' => $ticketType->id,
                    'event_id' => $ticketType->event_id,
                    'ticket_type_name' => $ticketType->name,
                ],
            ]);

            // Générer le token de paiement
            $token = $transaction->generateToken();

            return [
                'transaction_id' => $transaction->id,
                'token' => $token->token,
                'payment_url' => $token->url,
                'amount' => $ticketType->price,
                'currency' => config('services.fedapay.currency', 'XOF'),
            ];
        } catch (\Exception $e) {
            Log::error('FedaPay payment link creation failed', [
                'ticket_type_id' => $ticketTypeId,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to create payment link: ' . $e->getMessage());
        }
    }

    public function createTransaction(array $data): array
    {
        try {
            $transaction = Transaction::create([
                'description' => $data['description'] ?? 'Ticket Purchase',
                'amount' => $data['amount'],
                'currency' => ['iso' => $data['currency'] ?? config('services.fedapay.currency', 'XOF')],
                'callback_url' => $data['callback_url'] ?? route('payment.callback'),
                'custom_metadata' => $data['metadata'] ?? [],
            ]);

            $token = $transaction->generateToken();

            return [
                'transaction_id' => $transaction->id,
                'token' => $token->token,
                'payment_url' => $token->url,
                'amount' => $data['amount'],
            ];
        } catch (\Exception $e) {
            Log::error('FedaPay transaction creation failed', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to create transaction: ' . $e->getMessage());
        }
    }

    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        try {
            $endpointSecret = config('services.fedapay.webhook_secret');

            if (!$endpointSecret) {
                Log::warning('FedaPay webhook secret not configured');
                return false;
            }

            if (!$signature) {
                return false;
            }

            // Vérifier la signature du webhook
            Webhook::constructEvent($payload, $signature, $endpointSecret);

            return true;
        } catch (\Exception $e) {
            Log::error('FedaPay webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function handleWebhookEvent(array $eventData): void
    {
        $eventType = $eventData['name'] ?? null;
        $entity = $eventData['entity'] ?? [];

        Log::info('FedaPay webhook received', [
            'event_type' => $eventType,
            'entity_id' => $entity['id'] ?? null,
        ]);

        switch ($eventType) {
            case 'transaction.approved':
                $this->handleTransactionApproved($entity);
                break;

            case 'transaction.canceled':
                $this->handleTransactionCanceled($entity);
                break;

            case 'transaction.created':
                $this->handleTransactionCreated($entity);
                break;

            default:
                Log::info('Unhandled FedaPay webhook event', ['event_type' => $eventType]);
        }
    }

    protected function handleTransactionApproved(array $entity): void
    {
        $metadata = $entity['custom_metadata'] ?? [];
        $paymentId = $metadata['payment_id'] ?? null;

        if (!$paymentId) {
            Log::warning('Transaction approved but no payment_id in metadata', ['entity' => $entity]);
            return;
        }

        try {
            // Récupérer le payment
            $payment = \App\Models\Payment::find($paymentId);

            if (!$payment) {
                Log::error('Payment not found', ['payment_id' => $paymentId]);
                return;
            }

            // Marquer le payment comme approuvé
            $payment->update([
                'status' => 'approved',
                'paid_at' => now(),
                'fedapay_reference' => $entity['reference'] ?? null,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'fedapay_approved_at' => now()->toISOString(),
                    'fedapay_amount' => $entity['amount'] ?? null,
                ]),
            ]);

            // Mettre à jour tous les tickets liés
            $payment->tickets()->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Envoyer notifications
            // 1. Confirmation de paiement
            $this->notificationService->sendPaymentConfirmation($payment->tickets->first()->id, [
                'payment_id' => $payment->id,
                'transaction_id' => $entity['id'],
                'reference' => $entity['reference'] ?? null,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'ticket_count' => $payment->ticket_count,
            ]);

            // 2. Envoyer les tickets individuels par email
            foreach ($payment->tickets as $ticket) {
                $this->notificationService->sendTicketConfirmation($ticket->id);
            }

            Log::info('Payment approved and tickets updated', [
                'payment_id' => $payment->id,
                'ticket_count' => $payment->tickets->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process approved payment', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function handleTransactionCanceled(array $entity): void
    {
        $metadata = $entity['custom_metadata'] ?? [];
        $ticketId = $metadata['ticket_id'] ?? null;

        if ($ticketId) {
            Log::info('Transaction canceled for ticket', [
                'ticket_id' => $ticketId,
                'transaction_id' => $entity['id'],
            ]);
        }
    }

    protected function handleTransactionCreated(array $entity): void
    {
        Log::info('Transaction created', ['transaction_id' => $entity['id']]);
    }
}
