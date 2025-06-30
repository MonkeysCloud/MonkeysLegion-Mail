<div class="email-card">
    @if($this->slot('title'))
    <h3>{{ $this->slot('title') }}</h3>
    @endif
    {{ $this->slot('default') }}
</div>