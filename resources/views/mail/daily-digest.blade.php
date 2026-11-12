<x-mail::message>
# {{ __('account.greeting', ['name' => $name]) }}

{{ __('account.digest_intro') }}

@foreach ($groups as $group)
@if (count($groups) > 1)
## {{ $group['workspace'] }}
@endif

<x-mail::table>
| | | |
|:--|:--|--:|
@foreach ($group['tasks'] as $task)
| {{ $task['overdue'] ? '🔴' : '🟢' }} | **{{ $task['title'] }}**{{ $task['related'] ? ' · '.$task['related'] : '' }} | {{ $task['overdue'] ? __('account.digest_overdue').' · ' : '' }}{{ $task['due'] }} |
@endforeach
</x-mail::table>
@endforeach

<x-mail::button :url="$url">
{{ __('account.digest_action') }}
</x-mail::button>

{!! __('account.signature') !!}

<x-slot:subcopy>
{{ __('account.unsubscribe_hint') }}
</x-slot:subcopy>
</x-mail::message>
