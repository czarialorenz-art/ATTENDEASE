<x-mail::layout>
{{-- Header (FR-MAIL-03: shared layout showing SCHOOL_NAME) --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.school_name') }}<br>
<span style="font-size: 13px; font-weight: normal;">{{ config('app.name') }}</span>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
&copy; {{ date('Y') }} {{ config('app.school_name') }} &middot; {{ config('app.name') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
