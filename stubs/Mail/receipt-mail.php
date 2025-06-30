<?php

declare(strict_types=1);

namespace App\Mail;

use MonkeysLegion\Mail\Mail\Mailable;

/**
 * Receipt Mail Class
 * 
 * Sends transaction receipts to customers.
 */
class ReceiptMail extends Mailable
{
    /**
     * Mail-specific timeout override (optional)
     */
    protected ?int $timeout = 45;

    /**
     * Max retry attempts override (optional)
     */
    protected ?int $maxTries = 5;

    /**
     * Create a new receipt mail instance.
     * 
     * @param string $customerName The customer's name
     * @param string $customerEmail The customer's email address
     * @param string $orderNumber The order number
     * @param array $orderData Order details and items
     */
    public function __construct(
        private string $customerName,
        private string $customerEmail,
        private string $orderNumber,
        private array $orderData
    ) {
        parent::__construct();
    }

    /**
     * Build the receipt mail message.
     * 
     * @return self
     */
    public function build(): self
    {
        return $this
            ->view('emails.receipt')
            ->to($this->customerEmail)
            ->subject("Receipt for Order #{$this->orderNumber}")
            ->contentType('text/html')
            ->onQueue('receipts')
            ->withData([
                'customerName' => $this->customerName,
                'appName' => 'Your Store Name',
                'orderNumber' => $this->orderNumber,
                'orderDate' => $this->orderData['date'] ?? date('Y-m-d'),
                'paymentMethod' => $this->orderData['payment_method'] ?? 'Credit Card',
                'transactionId' => $this->orderData['transaction_id'] ?? null,
                'items' => $this->orderData['items'] ?? [],
                'subtotal' => $this->orderData['subtotal'] ?? 0,
                'tax' => $this->orderData['tax'] ?? 0,
                'shipping' => $this->orderData['shipping'] ?? 0,
                'discount' => $this->orderData['discount'] ?? 0,
                'totalAmount' => $this->orderData['total'] ?? 0,
                'currencySymbol' => '$',
                'taxRate' => $this->orderData['tax_rate'] ?? 0,
                'billingAddress' => $this->orderData['billing_address'] ?? null,
                'shippingAddress' => $this->orderData['shipping_address'] ?? null,
                'trackingInfo' => $this->orderData['tracking_info'] ?? null,
                'supportEmail' => 'support@yourstore.com',
                'supportPhone' => $this->orderData['support_phone'] ?? null,
                'returnPolicy' => $this->orderData['return_policy_url'] ?? null,
                'termsUrl' => $this->orderData['terms_url'] ?? null,
                'orderDetailsUrl' => $this->orderData['order_details_url'] ?? null
            ]);
    }

    /**
     * Runtime configuration examples:
     * 
     * $orderData = [
     *     'date' => '2024-01-15',
     *     'total' => 99.99,
     *     'items' => [...]
     * ];
     * $mail = new ReceiptMail('John', 'john@example.com', 'ORD-12345', $orderData);
     * 
     * // Runtime setters
     * $mail->setTo('accounting@company.com')
     *      ->setSubject('Copy: Receipt for Order #' . $orderNumber);
     *
     * // Conditional configuration
     * $mail->when($isVipCustomer, function($mail) {
     *     $mail->setQueue('vip_receipts');
     * });
     */
}
