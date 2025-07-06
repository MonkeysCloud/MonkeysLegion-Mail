<x-email-layout>
    @slot('subject')
    Receipt for Order #{{ $orderNumber }} - {{ $appName }}
    @endslot

    <x-email-header>
        <h1>Payment Receipt</h1>
        <p>Order #{{ $orderNumber }}</p>
    </x-email-header>

    <x-email-content>
        <p>Hello {{ $customerName }},</p>

        <p>Thank you for your purchase! This email confirms that we have received your payment.</p>

        <x-email-card>
            @slot('title')Order Summary@endslot
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td><strong>Order Number:</strong></td>
                    <td>{{ $orderNumber }}</td>
                </tr>
                <tr>
                    <td><strong>Order Date:</strong></td>
                    <td>{{ $orderDate }}</td>
                </tr>
                <tr>
                    <td><strong>Payment Method:</strong></td>
                    <td>{{ $paymentMethod }}</td>
                </tr>
                @if($transactionId)
                <tr>
                    <td><strong>Transaction ID:</strong></td>
                    <td>{{ $transactionId }}</td>
                </tr>
                @endif
            </table>
        </x-email-card>

        <x-email-card>
            @slot('title')Items Purchased@endslot
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="border-bottom: 2px solid #ddd;">
                        <th style="text-align: left; padding: 8px;">Item</th>
                        <th style="text-align: center; padding: 8px;">Qty</th>
                        <th style="text-align: right; padding: 8px;">Price</th>
                        <th style="text-align: right; padding: 8px;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 8px;">
                            <strong>{{ $item['name'] }}</strong>
                            @if($item['description'])
                            <br><small>{{ $item['description'] }}</small>
                            @endif
                        </td>
                        <td style="text-align: center; padding: 8px;">{{ $item['quantity'] }}</td>
                        <td style="text-align: right; padding: 8px;">{{ $currencySymbol }}{{ number_format($item['price'], 2) }}</td>
                        <td style="text-align: right; padding: 8px;">{{ $currencySymbol }}{{ number_format($item['total'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </x-email-card>

        <x-email-card>
            @slot('title')Payment Details@endslot
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td><strong>Subtotal:</strong></td>
                    <td style="text-align: right;">{{ $currencySymbol }}{{ number_format($subtotal, 2) }}</td>
                </tr>
                @if($discount > 0)
                <tr>
                    <td><strong>Discount:</strong></td>
                    <td style="text-align: right; color: green;">-{{ $currencySymbol }}{{ number_format($discount, 2) }}</td>
                </tr>
                @endif
                @if($tax > 0)
                <tr>
                    <td><strong>Tax ({{ $taxRate }}%):</strong></td>
                    <td style="text-align: right;">{{ $currencySymbol }}{{ number_format($tax, 2) }}</td>
                </tr>
                @endif
                @if($shipping > 0)
                <tr>
                    <td><strong>Shipping:</strong></td>
                    <td style="text-align: right;">{{ $currencySymbol }}{{ number_format($shipping, 2) }}</td>
                </tr>
                @endif
                <tr style="border-top: 2px solid #ddd; font-size: 1.2em;">
                    <td><strong>Total Paid:</strong></td>
                    <td style="text-align: right;"><strong>{{ $currencySymbol }}{{ number_format($totalAmount, 2) }}</strong></td>
                </tr>
            </table>
        </x-email-card>

        @if($billingAddress || $shippingAddress)
        <x-email-card>
            @slot('title')Addresses@endslot
            <div style="display: flex; gap: 20px;">
                @if($billingAddress)
                <div style="flex: 1;">
                    <h4>Billing Address:</h4>
                    <p>
                        {{ $billingAddress['name'] }}<br>
                        {{ $billingAddress['line1'] }}<br>
                        @if($billingAddress['line2'])
                        {{ $billingAddress['line2'] }}<br>
                        @endif
                        {{ $billingAddress['city'] }}, {{ $billingAddress['state'] }} {{ $billingAddress['zip'] }}<br>
                        {{ $billingAddress['country'] }}
                    </p>
                </div>
                @endif

                @if($shippingAddress)
                <div style="flex: 1;">
                    <h4>Shipping Address:</h4>
                    <p>
                        {{ $shippingAddress['name'] }}<br>
                        {{ $shippingAddress['line1'] }}<br>
                        @if($shippingAddress['line2'])
                        {{ $shippingAddress['line2'] }}<br>
                        @endif
                        {{ $shippingAddress['city'] }}, {{ $shippingAddress['state'] }} {{ $shippingAddress['zip'] }}<br>
                        {{ $shippingAddress['country'] }}
                    </p>
                </div>
                @endif
            </div>
        </x-email-card>
        @endif

        @if($trackingInfo)
        <x-email-card>
            @slot('title')Shipping Information@endslot
            <p><strong>Tracking Number:</strong> {{ $trackingInfo['number'] }}</p>
            <p><strong>Carrier:</strong> {{ $trackingInfo['carrier'] }}</p>
            <p><strong>Estimated Delivery:</strong> {{ $trackingInfo['estimatedDelivery'] }}</p>
            @if($trackingInfo['url'])
            <x-email-button href="{{ $trackingInfo['url'] }}">
                Track Package
            </x-email-button>
            @endif
        </x-email-card>
        @endif

        <p>You can view your order details and download this receipt anytime by logging into your account.</p>

        <x-email-button href="{{ $orderDetailsUrl }}">
            View Order Details
        </x-email-button>

        <p>If you have any questions about your order, please contact our customer support team at {{ $supportEmail }} or {{ $supportPhone }}.</p>

        <p>Thank you for choosing {{ $appName }}!</p>

        <p>Best regards,<br>
            The {{ $appName }} Team</p>
    </x-email-content>

    <x-email-footer>
        <p>&copy; {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
        <p>This is an automated receipt. Please keep this email for your records.</p>
        @if($returnPolicy)
        <p><a href="{{ $returnPolicy }}">Return Policy</a> | <a href="{{ $termsUrl }}">Terms of Service</a></p>
        @endif
    </x-email-footer>
</x-email-layout>