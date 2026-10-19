<?php

namespace App\Services\Contracts;

interface PaymentServiceContract
{
    public function createPaymentLinkForTicketType(string $ticketTypeId): array;

    public function createTransaction(array $data): array;

    /**
     * Create a payment transaction for a specific ticket purchase
     *
     * @param array $ticketIds The tickets IDs being purchased
     * @param array $customerData Customer information (firstname, lastname, email, phone_number)
     * @param int $amount Total amount
     * @param string $description Transaction description
     * @return array Payment information including payment_url
     */
    //public function createTransactionForTicket(string $ticketId, array $customerData, int $amount, string $description): array;
    public function createTransactionForTicket(array $ticketIds, array $customerData, int $amount, string $description): array;

    public function verifyWebhookSignature(string $payload, ?string $signature): bool;

    public function handleWebhookEvent(array $eventData): void;
}
