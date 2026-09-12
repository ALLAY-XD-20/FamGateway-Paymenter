@if ($checkoutUrl)
    <meta http-equiv="refresh" content="0; url={{ $checkoutUrl }}">
@endif

<body class="min-h-screen flex items-center justify-center">
    <div class="flex flex-col items-center gap-6 p-8 text-center">
        <p class="font-bold">Redirecting you to FamGateway to complete your payment&hellip;</p>

        @if ($qrUrl)
            <img src="{{ $qrUrl }}" alt="Scan to pay with UPI" class="w-48 h-48" />
            <p class="text-sm">Or scan the QR code above with any UPI app</p>
        @endif

        @if ($checkoutUrl)
            <a href="{{ $checkoutUrl }}" class="underline">
                Click here if you are not redirected automatically
            </a>
        @endif
    </div>
</body>

@if ($checkoutUrl)
    @script
    <script>
        window.location.href = "{{ $checkoutUrl }}";
    </script>
    @endscript
@endif
