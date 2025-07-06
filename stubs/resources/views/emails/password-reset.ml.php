<x-email-layout>
    @slot('subject')
    Reset Your {{ $appName }} Password
    @endslot

    <x-email-header>
        <h1>Password Reset Request</h1>
    </x-email-header>

    <x-email-content>
        <p>Hello {{ $userName }},</p>

        <p>You are receiving this email because we received a password reset request for your {{ $appName }} account.</p>

        <x-email-card>
            @slot('title')Security Information@endslot
            <p><strong>Request Details:</strong></p>
            <ul>
                <li><strong>Time:</strong> {{ $requestTime }}</li>
                <li><strong>IP Address:</strong> {{ $ipAddress }}</li>
                <li><strong>User Agent:</strong> {{ $userAgent }}</li>
            </ul>
        </x-email-card>

        <p>Click the button below to reset your password:</p>

        <x-email-button href="{{ $resetUrl }}">
            Reset Password
        </x-email-button>

        <p><strong>Important Security Notes:</strong></p>
        <ul>
            <li>This password reset link will expire in {{ $resetExpiry }} minutes</li>
            <li>If you did not request a password reset, please ignore this email</li>
            <li>Never share this link with anyone</li>
            <li>{{ $appName }} will never ask for your password via email</li>
        </ul>

        <p>If you're unable to click the button above, copy and paste the following URL into your browser:</p>
        <p><code>{{ $resetUrl }}</code></p>

        @if($supportContact)
        <p>If you did not request this password reset or have concerns about your account security, please contact us immediately at {{ $supportContact }}.</p>
        @endif

        <p>Best regards,<br>
            The {{ $appName }} Security Team</p>
    </x-email-content>

    <x-email-footer>
        <p>&copy; {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
        <p><strong>Security Notice:</strong> This is an automated security email. Please do not reply to this message.</p>
    </x-email-footer>
</x-email-layout>