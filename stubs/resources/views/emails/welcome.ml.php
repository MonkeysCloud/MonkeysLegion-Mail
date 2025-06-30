<x-email-layout>
    <x-slot:subject>
        Welcome to {{ $appName }}, {{ $userName }}!
    </x-slot:subject>

    <x-email-header>
        <h1>Welcome to {{ $appName }}!</h1>
    </x-email-header>

    <x-email-content>
        <p>Hello {{ $userName }},</p>

        <p>We're excited to welcome you to {{ $appName }}! Your account has been successfully created and you're now part of our community.</p>

        <x-email-card>
            <x-slot:title>Getting Started</x-slot:title>
            <p>Here are a few things you can do to get started:</p>
            <ul>
                <li>Complete your profile setup</li>
                <li>Explore our features and services</li>
                <li>Connect with other members</li>
                @if($verificationRequired)
                <li><strong>Verify your email address using the link below</strong></li>
                @endif
            </ul>
        </x-email-card>

        @if($verificationRequired)
        <x-email-button href="{{ $verificationUrl }}">
            Verify Email Address
        </x-email-button>

        <p><small>This verification link will expire in {{ $verificationExpiry }} hours.</small></p>
        @endif

        <p>If you have any questions or need assistance, please don't hesitate to contact our support team at {{ $supportEmail }}.</p>

        <p>Best regards,<br>
            The {{ $appName }} Team</p>
    </x-email-content>

    <x-email-footer>
        <p>&copy; {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
        @if($unsubscribeUrl)
        <p><a href="{{ $unsubscribeUrl }}">Unsubscribe</a> from welcome emails.</p>
        @endif
    </x-email-footer>
</x-email-layout>